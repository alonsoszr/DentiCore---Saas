<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * La autorización gruesa (solo clinic_admin) la aplica `role:clinic_admin` en la ruta; que el
     * usuario pertenezca a la misma clínica lo verifica UserService::findForTenant (404).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'email',
                'max:180',
                Rule::unique('users', 'email')
                    ->where('tenant_id', TenantContext::id())
                    ->ignore($this->route('user'), 'uuid'),
            ],
            'password' => ['sometimes', 'string', Password::defaults()],
            'role' => ['sometimes', 'string', Rule::in(User::TENANT_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            // RN-75, RF-043: quien pasa a odontólogo sin COP registrado debe indicarlo.
            'cop_number' => [
                Rule::requiredIf(fn (): bool => $this->input('role') === 'dentist' && User::query()
                    ->where('tenant_id', TenantContext::id())
                    ->where('uuid', $this->route('user'))
                    ->value('cop_number') === null),
                'nullable',
                'string',
                'max:10',
                Rule::unique('users', 'cop_number')
                    ->where('tenant_id', TenantContext::id())
                    ->ignore($this->route('user'), 'uuid'),
            ],
        ];
    }
}
