<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    /** Antes de validar: quien no administra el equipo recibe 403, no un 422 que revela las reglas. */
    public function authorize(): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (bool) $this->user()?->roleIn($organization)?->canManageOrganization();
    }

    /** «Ana@Empresa.com » y «ana@empresa.com» son el mismo correo: se normaliza en vez de rechazar. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'agent'])],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Elige administrador o agente.',
        ];
    }
}
