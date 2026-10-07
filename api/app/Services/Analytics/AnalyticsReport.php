<?php

namespace App\Services\Analytics;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analíticas de una empresa en una ventana de días calendario (en SU zona horaria, hoy incluido), comparada con la
 * ventana anterior de igual duración. Todo sale de datos que ya se guardan: conversaciones, mensajes y leads.
 *
 * Definiciones (las que el panel muestra al pasar el cursor):
 *  - Conversaciones: las que empezaron en la ventana.
 *  - Con lead: de esas, las que dejaron datos de contacto.
 *  - Pidieron una persona: de esas, las que el visitante o el agente derivaron (aunque luego se devolvieran a la IA).
 *  - Primera respuesta humana: mediana de minutos entre el pedido de una persona y el primer mensaje del equipo.
 *  - Sin respuesta en la base: preguntas del visitante que el agente contestó sin ningún fragmento relevante de conocimiento.
 *
 * Se llama con la empresa actual fijada: los modelos ya salen acotados a ella por su global scope.
 */
class AnalyticsReport
{
    public const PERIODS = [7, 30, 90];

    private const GAPS_LIMIT = 10;

    /** @return array<string, mixed> */
    public function build(Organization $organization, int $days): array
    {
        $timezone = $organization->timezone;
        $first = Carbon::now($timezone)->subDays($days - 1)->startOfDay();
        $end = Carbon::now($timezone)->addDay()->startOfDay();
        $previousFirst = $first->clone()->subDays($days);

        return [
            'days' => $days,
            'from' => $first->toDateString(),
            'to' => $end->clone()->subDay()->toDateString(),
            'totals' => $this->totals($organization, $first, $end),
            'previous' => $this->totals($organization, $previousFirst, $first),
            'daily' => $this->daily($first, $end, $days, $timezone),
            'by_hour' => $this->byHour($first, $end, $timezone),
            'by_agent' => $this->byAgent($first, $end),
            'gaps' => $this->gaps($organization, $first, $end),
        ];
    }

    /** @return array<string, int|float|null> */
    private function totals(Organization $organization, Carbon $from, Carbon $to): array
    {
        $from = $from->clone()->utc();
        $to = $to->clone()->utc();

        $conversations = fn () => Conversation::where('created_at', '>=', $from)->where('created_at', '<', $to);

        $total = $conversations()->count();
        $withLead = $conversations()->whereExists(fn ($q) => $q->selectRaw('1')->from('leads')->whereColumn('leads.conversation_id', 'conversations.id'))->count();
        $handoffs = $conversations()->whereNotNull('handoff_requested_at')->count();

        return [
            'conversations' => $total,
            'visitors' => $conversations()->distinct()->count('contact_id'),
            'visitor_messages' => Message::where('role', 'user')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'leads' => Lead::where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'with_lead_rate' => $this->rate($withLead, $total),
            'handoff_rate' => $this->rate($handoffs, $total),
            'ai_only_rate' => $total > 0 ? $this->rate($total - $handoffs, $total) : null,
            'messages_per_conversation' => $total > 0 ? round((float) $conversations()->avg('message_count'), 1) : null,
            'first_human_response_minutes' => $this->firstHumanResponse($organization, $from, $to),
        ];
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    /** Mediana, en minutos, entre que se pidió una persona y respondió alguien del equipo. null si no hubo ninguna. */
    private function firstHumanResponse(Organization $organization, Carbon $from, Carbon $to): ?float
    {
        $row = DB::selectOne(
            "select percentile_cont(0.5) within group (order by extract(epoch from (r.first_at - c.handoff_requested_at))) as seconds
             from conversations c
             join lateral (
                 select min(m.created_at) as first_at from messages m
                 where m.conversation_id = c.id and m.organization_id = c.organization_id and m.role = 'human' and m.created_at >= c.handoff_requested_at
             ) r on r.first_at is not null
             where c.organization_id = ? and c.handoff_requested_at >= ? and c.handoff_requested_at < ?",
            [$organization->id, $from, $to],
        );

        return $row?->seconds !== null ? round(((float) $row->seconds) / 60, 1) : null;
    }

    /** @return list<array{date: string, conversations: int, leads: int}> */
    private function daily(Carbon $first, Carbon $end, int $days, string $timezone): array
    {
        $day = "(created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date";
        $since = $first->clone()->utc();
        $until = $end->clone()->utc();

        $conversations = Conversation::where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->selectRaw("$day as day, count(*) as total", [$timezone])->groupBy('day')->pluck('total', 'day');
        $leads = Lead::where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->selectRaw("$day as day, count(*) as total", [$timezone])->groupBy('day')->pluck('total', 'day');

        return collect(range(0, $days - 1))->map(function (int $i) use ($first, $conversations, $leads) {
            $date = $first->clone()->addDays($i)->toDateString();

            return ['date' => $date, 'conversations' => (int) ($conversations[$date] ?? 0), 'leads' => (int) ($leads[$date] ?? 0)];
        })->all();
    }

    /** Conversaciones que empezaron en cada hora del día (0–23) de la zona horaria de la empresa. */
    private function byHour(Carbon $first, Carbon $end, string $timezone): array
    {
        $counts = Conversation::where('created_at', '>=', $first->clone()->utc())->where('created_at', '<', $end->clone()->utc())
            ->selectRaw("extract(hour from (created_at AT TIME ZONE 'UTC' AT TIME ZONE ?))::int as hour, count(*) as total", [$timezone])
            ->groupBy('hour')->pluck('total', 'hour');

        return collect(range(0, 23))->map(fn (int $h) => (int) ($counts[$h] ?? 0))->all();
    }

    /** @return list<array{agent: string, conversations: int, leads: int, handoffs: int}> */
    private function byAgent(Carbon $first, Carbon $end): array
    {
        return Conversation::where('created_at', '>=', $first->clone()->utc())->where('created_at', '<', $end->clone()->utc())
            ->selectRaw('agent_name, count(*) as conversations, count(handoff_requested_at) as handoffs')
            ->selectRaw('count(*) filter (where exists (select 1 from leads where leads.conversation_id = conversations.id)) as leads')
            ->groupBy('agent_id', 'agent_name')->orderByDesc('conversations')->get()
            ->map(fn ($row) => [
                'agent' => $row->agent_name,
                'conversations' => (int) $row->conversations,
                'leads' => (int) $row->leads,
                'handoffs' => (int) $row->handoffs,
            ])->all();
    }

    /**
     * Lo que los visitantes preguntaron y el agente contestó sin encontrar nada en la base de conocimiento: lo que
     * conviene cargar. Solo cuenta agentes que tienen una base asignada (sin base, todo sería «sin respuesta»).
     *
     * @return list<array{question: string, count: int, last_at: string}>
     */
    private function gaps(Organization $organization, Carbon $first, Carbon $end): array
    {
        $rows = DB::select(
            "select lower(btrim(q.content)) as key, btrim((array_agg(q.content order by a.created_at desc))[1]) as question, count(*) as total, max(a.created_at) as last_at
             from messages a
             join conversations c on c.id = a.conversation_id and c.organization_id = a.organization_id
             join agents ag on ag.id = c.agent_id and ag.organization_id = a.organization_id and ag.knowledge_base_id is not null
             join lateral (
                 select u.content from messages u
                 where u.conversation_id = a.conversation_id and u.organization_id = a.organization_id and u.role = 'user' and u.id < a.id
                 order by u.id desc limit 1
             ) q on true
             where a.organization_id = ? and a.role = 'assistant' and a.created_at >= ? and a.created_at < ?
               and (a.sources is null or jsonb_array_length(a.sources) = 0)
             group by 1 order by total desc, last_at desc limit ".self::GAPS_LIMIT,
            [$organization->id, $first->clone()->utc(), $end->clone()->utc()],
        );

        return collect($rows)->map(fn ($row) => [
            'question' => mb_strimwidth($row->question, 0, 160, '…'),
            'count' => (int) $row->total,
            'last_at' => Carbon::parse($row->last_at, 'UTC')->toIso8601String(),
        ])->all();
    }
}
