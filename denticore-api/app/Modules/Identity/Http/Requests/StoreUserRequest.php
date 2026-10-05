<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * La autorización gruesa (solo clinic_admin) la aplica `role:clinic_admin` en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El email es único por clínica, no global (UNIQUE(tenant_id, email), SDD §2.4). tenant_id no
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
                Rule::unique('users', 'email')->where('tenant_id', TenantContext::id()),
            ],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', 'string', Rule::in(User::TENANT_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            // RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.
            'cop_number' => [
                'required_if:role,dentist',
                'nullable',
                'string',
                'max:10',
                Rule::unique('users', 'cop_number')->where('tenant_id', TenantContext::id()),
            ],
        ];
    }
}
