<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * tenant_slug es opcional: su ausencia indica un intento de login de super_admin
     * (usuario de plataforma sin clínica asociada).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_slug' => ['nullable', 'string', 'exists:tenants,slug'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
