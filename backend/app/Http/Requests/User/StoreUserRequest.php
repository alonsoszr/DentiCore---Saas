<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * La autorización gruesa (solo clinic_admin) la aplica EnsureRole en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El email es único por clínica, no global (technical_specs.md §3.3). tenant_id no
     * se valida porque nunca se acepta desde el payload.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:180',
                Rule::unique('users', 'email')->where('tenant_id', app('currentTenant')->id),
            ],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', 'string', Rule::in(User::TENANT_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
