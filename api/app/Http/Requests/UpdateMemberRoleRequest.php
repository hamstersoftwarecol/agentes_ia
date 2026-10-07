<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    // La autorización fina (quién puede tocar a quién) la decide TeamService con el rol de cada persona.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::in(['admin', 'agent'])]];
    }
}
