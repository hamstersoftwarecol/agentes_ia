<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;

/**
 * Proveedor determinista para desarrollar y testear sin API keys. No razona: aplica reglas simples
 * (palabras clave) para poder recorrer de punta a punta el RAG, la captura de leads y el paso a una persona.
 */
class MockAIProvider implements AIProvider
{
    private const HUMAN = '/\b(persona real|una persona|humano|asesor(?:a)?|ejecutivo|con alguien)\b/iu';

    private const INTENT = '/\b(quiero|me interesa|necesito|contratar|comprar|cotiz\w*|reservar|agendar|precios?|plan)\b/iu';

    public function chat(array $messages, ?string $system = null, ?array $jsonSchema = null): ChatResponse
    {
        $last = collect($messages)->last(fn (ChatMessage $m) => $m->role === 'user');

        if ($jsonSchema !== null) {
            $payload = $this->structuredTurn($messages, $last?->content, $system);
            $reply = json_encode($payload, JSON_UNESCAPED_UNICODE);
        } elseif (! $last) {
            $reply = '[mock] ¡Hola! ¿En qué puedo ayudarte?';
        } else {
            $reply = $this->answer($last->content, $system);
        }

        return new ChatResponse(
            content: $reply,
            model: 'mock-1',
            inputTokens: (int) ceil(mb_strlen(($system ?? '').implode(' ', array_map(fn ($m) => $m->content, $messages))) / 4),
            outputTokens: (int) ceil(mb_strlen($reply) / 4),
        );
    }

    /** @return array{reply: string, action: string, lead: ?array<string, ?string>} */
    private function structuredTurn(array $messages, ?string $text, ?string $system): array
    {
        $userText = collect($messages)->where('role', 'user')->map(fn (ChatMessage $m) => $m->content)->implode("\n");
        $lead = $this->leadFrom($userText);

        if ($text !== null && preg_match(self::HUMAN, $text)) {
            return [
                'reply' => '[mock] Claro, voy a transferirte con una persona del equipo. Te atenderán en cuanto sea posible.',
                'action' => 'request_human',
                'lead' => $lead,
            ];
        }

        if ($lead && ($lead['phone'] || $lead['email'])) {
            $name = $lead['name'] ? " {$lead['name']}" : '';

            return [
                'reply' => "[mock] ¡Gracias{$name}! Un asesor se comunicará contigo muy pronto.",
                'action' => 'none',
                'lead' => $lead,
            ];
        }

        if ($text !== null && preg_match(self::INTENT, $text)) {
            return [
                'reply' => '[mock] ¡Perfecto! Para ayudarte mejor, ¿cómo te llamas y cuál es tu WhatsApp o correo?',
                'action' => 'none',
                'lead' => null,
            ];
        }

        return ['reply' => $text === null ? '[mock] ¡Hola! ¿En qué puedo ayudarte?' : $this->answer($text, $system), 'action' => 'none', 'lead' => null];
    }

    /** @return array{name: ?string, phone: ?string, email: ?string, interest: ?string}|null */
    private function leadFrom(string $userText): ?array
    {
        $email = preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $userText, $m) ? $m[0] : null;
        $phone = preg_match('/\+?\d[\d\s\-]{5,}\d/u', $userText, $m) ? trim($m[0]) : null;
        $name = preg_match('/\b(?i:me llamo|mi nombre es|soy)\s+([\p{Lu}][\p{L}]+(?:\s+[\p{Lu}][\p{L}]+)?)/u', $userText, $m) ? $m[1] : null;
        $interest = preg_match(self::INTENT, $userText) ? collect(preg_split('/\n/', $userText))->first(fn ($l) => preg_match(self::INTENT, $l)) : null;

        return $email || $phone || $name ? ['name' => $name, 'phone' => $phone, 'email' => $email, 'interest' => $interest] : null;
    }

    private function answer(string $question, ?string $system): string
    {
        $fragment = $this->firstKnowledgeFragment($system);

        return $fragment
            ? "[mock] Según la información de la empresa: «{$fragment}»"
            : "[mock] Recibí tu mensaje: «{$question}». No tengo información sobre eso en mi base de conocimiento.";
    }

    private function firstKnowledgeFragment(?string $system): ?string
    {
        if ($system && preg_match('/<conocimiento>\s*\[1\][^\n]*\n(.+?)(?:\n\n\[2\]|\n<\/conocimiento>)/su', $system, $m)) {
            return trim(mb_substr($m[1], 0, 300));
        }

        return null;
    }
}
