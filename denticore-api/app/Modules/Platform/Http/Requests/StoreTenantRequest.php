<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Services\RucValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de clínica con invitación (CUS-01; validaciones de SRS §11.1, RF-013, RF-014, DD-22).
 * El primer administrador no recibe contraseña: la define al activar su cuenta.
 */
class StoreTenantRequest extends FormRequest
{
    /**
     * La autorización (solo super_admin) la aplica el middleware `role:super_admin` de la ruta.
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
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'legal_name' => ['required', 'string', 'min:3', 'max:200'],
            'ruc' => ['required', 'string', self::rucRule(), Rule::unique('tenants', 'ruc')],
            // SRS §11.1: 3–50 caracteres [a-z0-9-], sin guion al inicio ni al final.
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{1,48})[a-z0-9]$/', Rule::unique('tenants', 'slug')],
            'address' => ['required', 'string', 'min:5', 'max:200'],
            'subscription_plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')],
            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'min:3', 'max:150'],
            'admin.email' => ['required', 'email:rfc', 'max:180'],
        ];
    }

    /**
     * RF-014: 11 dígitos, prefijo 10 o 20 y dígito verificador módulo 11.
     */
    public static function rucRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! RucValidator::isValid($value)) {
                $fail('El RUC no es válido: debe tener 11 dígitos, empezar con 10 o 20 y un dígito verificador correcto.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre comercial',
            'legal_name' => 'razón social',
            'slug' => 'código de acceso',
            'address' => 'dirección',
            'subscription_plan' => 'plan',
            'admin.name' => 'nombre del administrador',
            'admin.email' => 'correo del administrador',
        ];
    }
}
