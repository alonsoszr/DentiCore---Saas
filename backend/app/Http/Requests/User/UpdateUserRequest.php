<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * La autorización gruesa (solo clinic_admin) la aplica EnsureRole en la ruta; que el
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
                    ->where('tenant_id', app('currentTenant')->id)
                    ->ignore($this->route('user'), 'uuid'),
            ],
            'password' => ['sometimes', 'string', Password::defaults()],
            'role' => ['sometimes', 'string', Rule::in(User::TENANT_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
