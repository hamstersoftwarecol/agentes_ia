<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('knowledge_base'));
    }

    public function rules(): array
    {
        return ['query' => ['required', 'string', 'max:500']];
    }
}
