<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de un usuario de la clínica (CUS-11; RF-042, RF-043, RF-045, RF-047). La desactivación
 * y la reactivación tienen sus propias rutas; la contraseña la define solo su dueño.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $current = $this->targetUser();
        $role = $this->input('role', $current?->role);

        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'email' => [
                'sometimes',
                'email:rfc',
                'max:180',
                Rule::unique('users', 'email')
                    ->where('tenant_id', TenantContext::id())
                    ->ignore($this->route('user'), 'uuid'),
            ],
            'role' => ['sometimes', 'string', Rule::in(User::TENANT_ROLES)],
            // RN-75, RF-043: quien es (o pasa a ser) odontólogo debe tener COP.
            'cop_number' => [
                Rule::requiredIf(fn (): bool => $role === 'dentist' && $current?->cop_number === null),
                'nullable',
                'string',
                'max:10',
                Rule::unique('users', 'cop_number')
                    ->where('tenant_id', TenantContext::id())
                    ->ignore($this->route('user'), 'uuid'),
            ],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:100'],
            'rne_number' => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_data_officer' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => $this->boolean('is_data_officer') && $role !== 'clinic_admin')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_data_officer.prohibited' => 'Solo un Administrador de Clínica puede ser Oficial de Datos Personales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'role' => 'rol',
            'cop_number' => 'número de COP',
            'specialty' => 'especialidad',
            'rne_number' => 'número de RNE',
        ];
    }

    private function targetUser(): ?User
    {
        return User::query()->where('tenant_id', TenantContext::id())->where('uuid', $this->route('user'))->first();
    }
}
