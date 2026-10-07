<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WidgetMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint público: la autorización es el visitor_id + el aislamiento por agente
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'visitor_id' => $this->header('X-Visitor-Id'),
            'message' => is_string($this->message) ? trim($this->message) : $this->message,
        ]);
    }

    public function rules(): array
    {
        return [
            'visitor_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:'.config('widget.max_message_length')],
            'conversation_id' => ['nullable', 'uuid'],
            'page_url' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'visitor_id.required' => 'Falta el identificador del visitante.',
            'visitor_id.uuid' => 'Identificador de visitante inválido.',
            'message.required' => 'Escribe un mensaje.',
            'message.max' => 'El mensaje es demasiado largo.',
        ];
    }
}
