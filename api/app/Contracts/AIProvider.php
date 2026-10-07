<?php

namespace App\Contracts;

use App\Services\AI\ChatMessage;
use App\Services\AI\ChatResponse;

/**
 * Capa de proveedores de IA. El dominio solo conoce esta interfaz; cambiar de
 * OpenAI a Anthropic o Gemini es una línea en config/ai.php.
 */
interface AIProvider
{
    /**
     * @param  list<ChatMessage>  $messages  Conversación en orden cronológico.
     * @param  string|null  $system  Instrucciones del agente + contexto RAG.
     * @param  array<string, mixed>|null  $jsonSchema  Si se indica, `content` debe ser un JSON que cumpla ese esquema.
     */
    public function chat(array $messages, ?string $system = null, ?array $jsonSchema = null): ChatResponse;
}
