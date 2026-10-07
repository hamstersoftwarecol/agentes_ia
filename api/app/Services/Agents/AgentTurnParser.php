<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

/**
 * Esquema JSON que se le exige al modelo en cada turno, y su lectura defensiva.
 * Un solo mensaje del modelo trae la respuesta para el cliente y, si corresponde, las acciones:
 * así no hace falta un segundo viaje al modelo ni un bucle de herramientas.
 */
class AgentTurnParser
{
    /** @return array<string, mixed> Subconjunto de JSON Schema que aceptan las salidas estructuradas. */
    public static function schema(): array
    {
        $nullableString = fn (string $description) => [
            'anyOf' => [['type' => 'string'], ['type' => 'null']],
            'description' => $description,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'Mensaje para el cliente, en su idioma y con el tono del agente.'],
                'action' => [
                    'type' => 'string',
                    'enum' => [AgentTurn::ACTION_NONE, AgentTurn::ACTION_REQUEST_HUMAN],
                    'description' => 'request_human cuando haya que pasar la conversación a una persona; si no, none.',
                ],
                'lead' => [
                    'anyOf' => [
                        ['type' => 'null'],
                        [
                            'type' => 'object',
                            'properties' => [
                                'name' => $nullableString('Nombre que el cliente dio.'),
                                'phone' => $nullableString('Teléfono o WhatsApp que el cliente dio.'),
                                'email' => $nullableString('Correo que el cliente dio.'),
                                'interest' => $nullableString('Qué quiere el cliente, en una frase.'),
                            ],
                            'required' => ['name', 'phone', 'email', 'interest'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'description' => 'Datos de contacto del cliente, solo cuando los haya dado en la conversación.',
                ],
            ],
            'required' => ['reply', 'action', 'lead'],
            'additionalProperties' => false,
        ];
    }

    public function parse(string $raw): AgentTurn
    {
        $text = trim($raw);
        $data = json_decode($this->stripFences($text), true);

        // Sin JSON (proveedor sin salida estructurada): se usa el texto tal cual y no se actúa.
        if (! is_array($data)) {
            return new AgentTurn(reply: $text, structured: false);
        }

        // JSON sin `reply`: respuesta rota. Vacía a propósito para que el llamador falle en vez de mostrarle JSON crudo al cliente.
        if (! is_string($data['reply'] ?? null)) {
            return new AgentTurn(reply: '', structured: false);
        }

        return new AgentTurn(
            reply: trim($data['reply']),
            action: ($data['action'] ?? null) === AgentTurn::ACTION_REQUEST_HUMAN ? AgentTurn::ACTION_REQUEST_HUMAN : AgentTurn::ACTION_NONE,
            lead: $this->normalizeLead($data['lead'] ?? null),
        );
    }

    /** Algunos modelos envuelven el JSON en ```json … ```. */
    private function stripFences(string $text): string
    {
        return preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m) ? $m[1] : $text;
    }

    /**
     * Los datos vienen del texto libre de un visitante anónimo: nada se da por bueno.
     * Sin teléfono ni correo válidos no hay lead (no habría cómo contactar).
     */
    private function normalizeLead(mixed $lead): ?array
    {
        if (! is_array($lead)) {
            return null;
        }

        $normalized = [
            'name' => $this->text($lead['name'] ?? null, 120),
            'phone' => $this->phone($lead['phone'] ?? null),
            'email' => $this->email($lead['email'] ?? null),
            'interest' => $this->text($lead['interest'] ?? null, 500),
        ];

        return $normalized['phone'] || $normalized['email'] ? $normalized : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        // Sin caracteres de control ni saltos: el nombre y el interés se muestran en listas y correos.
        $clean = trim(preg_replace('/[\p{C}\s]+/u', ' ', $value));

        return $clean === '' ? null : Str::limit($clean, $max, '');
    }

    private function phone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return (str_starts_with(trim($value), '+') ? '+' : '').$digits;
    }

    private function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $email = Str::lower(trim($value));

        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
