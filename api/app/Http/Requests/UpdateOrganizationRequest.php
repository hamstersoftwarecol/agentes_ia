<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $this->user()->can('update', $organization);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            // all_with_bc: Chrome devuelve nombres heredados (p. ej. America/Buenos_Aires).
            'timezone' => ['required', 'timezone:all_with_bc'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->country)) {
            $this->merge(['country' => strtoupper($this->country)]);
        }
    }
}
