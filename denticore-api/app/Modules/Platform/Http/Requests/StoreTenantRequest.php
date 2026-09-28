<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * La autorización gruesa (solo super_admin) ya la aplica el middleware
     * `role:super_admin` en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `admin` es el primer clinic_admin de la clínica (contrato heredado de las fases 0–3;
     * TASK-023 lo reemplaza por la invitación de DD-22). No se valida su email como único porque la
     * clínica es nueva y aún no tiene usuarios.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'alpha_dash', 'unique:tenants,slug'],
            'subscription_plan' => ['required', 'string', 'in:basic,pro,enterprise'],
            'settings' => ['nullable', 'array'],
            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:150'],
            'admin.email' => ['required', 'email', 'max:180'],
            'admin.password' => ['required', 'string', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin.name' => 'nombre del administrador',
            'admin.email' => 'correo del administrador',
            'admin.password' => 'contraseña del administrador',
        ];
    }
}
