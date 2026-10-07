<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;

class ConversationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', Conversation::class);
    }

    protected function prepareForValidation(): void
    {
        // OJO: `$this->content` sería la propiedad protegida del Request con el cuerpo crudo, no el campo.
        $content = $this->input('content');
        $this->merge(['content' => is_string($content) ? trim($content) : $content]);
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:2000']];
    }
}
