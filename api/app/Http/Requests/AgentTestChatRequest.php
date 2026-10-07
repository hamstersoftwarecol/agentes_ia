<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AgentTestChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('agent'));
    }

    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $messages = $this->input('messages');
                if (is_array($messages) && ($messages[array_key_last($messages)]['role'] ?? null) !== 'user') {
                    $validator->errors()->add('messages', 'El último mensaje debe ser del cliente.');
                }
            },
        ];
    }
}
