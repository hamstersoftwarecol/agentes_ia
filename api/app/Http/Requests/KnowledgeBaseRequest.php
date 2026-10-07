<?php

namespace App\Http\Requests;

use App\Models\KnowledgeBase;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethod('POST')
            ? $this->user()->can('create', KnowledgeBase::class)
            : $this->user()->can('update', $this->route('knowledge_base'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
