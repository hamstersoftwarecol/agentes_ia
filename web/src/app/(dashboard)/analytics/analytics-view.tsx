"use client";

import Link from "next/link";
import { useState } from "react";
import { PlanLimitAlert, useBilling } from "@/components/BillingProvider";
import { DailyBars, HOUR_AXIS } from "@/components/charts/DailyBars";
import { Alert, Badge, Card, cn, Spinner } from "@/components/ui";
import { useAuth } from "@/lib/auth";
import { formatDateOnly, formatDecimal, formatMinutes, formatNumber, formatPercent } from "@/lib/format";
import type { Analytics, AnalyticsTotals } from "@/lib/types";
import { useApi } from "@/lib/use-api";

const PERIODS = [7, 30, 90] as const;
type Period = (typeof PERIODS)[number];

export function AnalyticsView() {
  const { user } = useAuth();
  const { billing } = useBilling();
  const [days, setDays] = useState<Period>(30);

  // Sin la función en el plan ni se consulta: se muestra qué incluye y cómo conseguirla.
  const locked = billing !== undefined && !billing.plan?.features.analytics;
  const report = useApi<Analytics>(billing && !locked ? `/analytics?days=${days}` : null);
  // Al cambiar de período se sigue viendo el anterior hasta que llega el nuevo: no se muestra como si fuera este.
  const data = report.data?.days === days ? report.data : undefined;

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Analíticas</h1>
          <p className="mt-1 text-sm text-muted">
            {data ? `Del ${formatDateOnly(data.from)} al ${formatDateOnly(data.to)}, comparado con los ${days} días anteriores.` : "Cómo atienden tus agentes y qué piden tus clientes."}
          </p>
        </div>
        {!locked && (
          <div role="group" aria-label="Período" className="inline-flex rounded-lg border border-border bg-card p-0.5">
            {PERIODS.map((period) => (
              <button
                key={period}
                type="button"
                aria-pressed={days === period}
                onClick={() => setDays(period)}
                className={cn("rounded-md px-3 py-1.5 text-sm", days === period ? "bg-brand-soft font-medium" : "text-muted hover:text-foreground")}
              >
                {period} días
              </button>
            ))}
          </div>
        )}
      </div>

      {locked && <Locked />}
      {report.error && <PlanLimitAlert error={report.error} />}
      {!locked && !data && !report.error && <Spinner className="size-5" />}

      {data && (
        <>
          <Kpis totals={data.totals} previous={data.previous} />

          <Card>
            <h2 className="font-semibold">Conversaciones por día</h2>
            <div className="mt-4">
              <DailyBars
                data={data.daily.map((d) => ({ date: d.date, value: d.conversations }))}
                unit={["conversación", "conversaciones"]}
                label={`Conversaciones por día, últimos ${days} días`}
              />
            </div>
          </Card>

          <Card>
            <h2 className="font-semibold">¿A qué hora te escriben?</h2>
            <p className="mt-0.5 text-sm text-muted">
              Conversaciones que empezaron en cada hora del día (hora de {user?.organization?.timezone ?? "tu empresa"}). Útil para decidir cuándo
              conviene que haya alguien del equipo disponible.
            </p>
            <div className="mt-4">
              <DailyBars
                data={data.by_hour.map((value, hour) => ({ date: String(hour), value }))}
                unit={["conversación", "conversaciones"]}
                label="Conversaciones por hora del día"
                axis={HOUR_AXIS}
              />
            </div>
          </Card>

          {data.by_agent.length > 0 && <ByAgent rows={data.by_agent} />}
          <Gaps gaps={data.gaps} />
        </>
      )}
    </div>
  );
}

function Locked() {
  return (
    <Card>
      <h2 className="font-semibold">Las analíticas están disponibles desde el plan Business</h2>
      <p className="mt-1 text-sm text-muted">Con ellas ves, de un vistazo:</p>
      <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
        <li>cuántas conversaciones terminan en un lead y cuántas necesitan a una persona;</li>
        <li>cuánto tarda tu equipo en responder cuando un cliente pide hablar con alguien;</li>
        <li>a qué horas escriben tus clientes;</li>
        <li>qué preguntas tu agente no supo responder con tu base de conocimiento, para que sepas qué cargar.</li>
      </ul>
      <Link href="/billing" className="mt-4 inline-block font-medium text-brand underline">
        Ver planes
      </Link>
    </Card>
  );
}

type Kpi = { label: string; value: string; caption: string; compare: string | null };

/** «▲ +12 % vs. período anterior». Sin color de bueno/malo: más derivaciones no es mejor ni peor por sí mismo. */
function changeText(now: number, before: number) {
  if (before === 0) return now === 0 ? null : "Antes: 0";
  const pct = Math.round(((now - before) / before) * 100);
  return `${pct > 0 ? "▲ +" : pct < 0 ? "▼ " : "= "}${pct} % vs. período anterior`;
}

const before = (value: number | null, show: (v: number | null) => string) => (value === null ? null : `Antes: ${show(value)}`);

function Kpis({ totals, previous }: { totals: AnalyticsTotals; previous: AnalyticsTotals }) {
  const kpis: Kpi[] = [
    {
      label: "Conversaciones",
      value: formatNumber(totals.conversations),
      caption: `${formatNumber(totals.visitors)} visitante${totals.visitors === 1 ? "" : "s"} · ${formatNumber(totals.visitor_messages)} mensajes suyos`,
      compare: changeText(totals.conversations, previous.conversations),
    },
    {
      label: "Leads",
      value: formatNumber(totals.leads),
      caption: `${formatPercent(totals.with_lead_rate)} de las conversaciones dejó un teléfono o correo`,
      compare: changeText(totals.leads, previous.leads),
    },
    {
      label: "Atendidas solo por la IA",
      value: formatPercent(totals.ai_only_rate),
      caption: "Conversaciones en las que nadie pidió hablar con una persona",
      compare: before(previous.ai_only_rate, formatPercent),
    },
    {
      label: "Pidieron una persona",
      value: formatPercent(totals.handoff_rate),
      caption: "El cliente o el agente pidieron a alguien del equipo (aunque luego se devolviera a la IA)",
      compare: before(previous.handoff_rate, formatPercent),
    },
    {
      label: "Primera respuesta humana",
      value: formatMinutes(totals.first_human_response_minutes),
      caption: "Mediana desde que se pide una persona hasta que alguien del equipo responde",
      compare: before(previous.first_human_response_minutes, formatMinutes),
    },
    {
      label: "Mensajes por conversación",
      value: formatDecimal(totals.messages_per_conversation),
      caption: "Entre el visitante, el agente y tu equipo",
      compare: before(previous.messages_per_conversation, formatDecimal),
    },
  ];

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {kpis.map((kpi) => (
        <Card key={kpi.label} className="p-4">
          <p className="text-sm text-muted">{kpi.label}</p>
          <p className="mt-1 text-2xl font-semibold">{kpi.value}</p>
          <p className="mt-1 text-xs text-muted">{kpi.caption}</p>
          {kpi.compare && <p className="mt-2 text-xs font-medium">{kpi.compare}</p>}
        </Card>
      ))}
    </div>
  );
}

function ByAgent({ rows }: { rows: Analytics["by_agent"] }) {
  return (
    <Card>
      <h2 className="font-semibold">Por agente</h2>
      <div className="mt-3 overflow-x-auto">
        <table className="w-full min-w-[26rem] text-left text-sm">
          <thead className="text-xs text-muted">
            <tr>
              <th className="py-2 pr-3 font-medium">Agente</th>
              <th className="px-3 py-2 text-right font-medium">Conversaciones</th>
              <th className="px-3 py-2 text-right font-medium">Leads</th>
              <th className="py-2 pl-3 text-right font-medium">Pidieron una persona</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.agent} className="border-t border-border">
                <td className="py-2 pr-3 font-medium">{row.agent}</td>
                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.conversations)}</td>
                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.leads)}</td>
                <td className="py-2 pl-3 text-right tabular-nums">{formatNumber(row.handoffs)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function Gaps({ gaps }: { gaps: Analytics["gaps"] }) {
  return (
    <Card>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="font-semibold">Preguntas sin respuesta en tu base de conocimiento</h2>
        <Link href="/knowledge" className="text-sm text-brand hover:underline">
          Ir a Conocimiento
        </Link>
      </div>
      <p className="mt-0.5 text-sm text-muted">
        Preguntas que tu agente contestó sin encontrar ningún dato relevante. Las que más se repiten son las que más conviene cargar. Incluye
        también saludos y charla; solo se revisan los agentes que tienen una base de conocimiento.
      </p>
      {gaps.length === 0 ? (
        <div className="mt-4">
          <Alert tone="success">Ninguna en este período: tu agente encontró información para todo lo que le preguntaron.</Alert>
        </div>
      ) : (
        <ul className="mt-3 divide-y divide-border">
          {gaps.map((gap) => (
            <li key={gap.question} className="flex items-start justify-between gap-3 py-2.5 text-sm">
              <span className="min-w-0 break-words">{gap.question}</span>
              <Badge tone={gap.count > 1 ? "info" : "neutral"}>
                {gap.count} {gap.count === 1 ? "vez" : "veces"}
              </Badge>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
