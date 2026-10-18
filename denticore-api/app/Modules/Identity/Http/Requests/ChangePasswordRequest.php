<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Cambio de la contraseña propia (CUS-78; RF-049, RF-040): exige la contraseña actual (422 si no
 * coincide) y la nueva cumple la política de DD-15, incluidas las 5 últimas.
 */
class ChangePasswordRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'current_password' => ['required', 'string', 'max:128', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || ! Hash::check($value, (string) $user->password)) {
                    $fail('La contraseña actual no es correcta.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy($user)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['current_password' => 'contraseña actual', 'password' => 'contraseña nueva'];
    }
}
