<?php

namespace App\Http\Requests;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(LeadStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'interest' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'El teléfono debe tener entre 7 y 15 dígitos (puede empezar con +).'];
    }
}
