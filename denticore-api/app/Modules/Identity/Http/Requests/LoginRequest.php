<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Inicio de sesión (CUS-06; SRS §11.2, Datos). La clínica la resuelve `tenant.slug:login` por el
 * código de acceso, con el mismo 401 si no existe (no se valida su existencia aquí para no
 * revelarla). La política de longitud mínima se aplica al definir la contraseña, no al ingresar.
 */
class LoginRequest extends FormRequest
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
            'tenant_slug' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }
}
