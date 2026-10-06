<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de usuario de la clínica con invitación (CUS-11; RF-042, RF-043, RF-047, DD-22): sin
 * contraseña, que el usuario define al activar su cuenta. `super_admin` no es asignable (§3.7).
 */
class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'email' => [
                'required',
                'email:rfc',
                'max:180',
                Rule::unique('users', 'email')->where('tenant_id', TenantContext::id()),
            ],
            'role' => ['required', 'string', Rule::in(User::TENANT_ROLES)],
            // RN-75, RF-043: número de COP obligatorio para odontólogos y único en la clínica.
            'cop_number' => [
                'required_if:role,dentist',
                'nullable',
                'string',
                'max:10',
                Rule::unique('users', 'cop_number')->where('tenant_id', TenantContext::id()),
            ],
            'specialty' => ['nullable', 'string', 'max:100'],
            'rne_number' => ['nullable', 'string', 'max:10'],
            // RF-047: solo un Administrador de Clínica puede ser Oficial de Datos Personales.
            'is_data_officer' => ['sometimes', 'boolean', Rule::prohibitedIf(fn (): bool => $this->boolean('is_data_officer') && $this->input('role') !== 'clinic_admin')],
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
}
