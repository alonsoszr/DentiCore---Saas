<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * La autorización gruesa (solo super_admin) ya la aplica el middleware
     * EnsureRole:super_admin en la ruta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
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
        ];
    }
}
