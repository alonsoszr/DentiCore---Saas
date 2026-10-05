<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de los datos de una clínica (CUS-01; RF-013, RF-014). El código de acceso no se
 * acepta: es inmutable (DD-29) y el plan se cambia con PUT …/plan (CUS-03).
 */
class UpdateTenantRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'legal_name' => ['sometimes', 'string', 'min:3', 'max:200'],
            'ruc' => [
                'sometimes', 'string', StoreTenantRequest::rucRule(),
                Rule::unique('tenants', 'ruc')->ignore($this->route('tenant'), 'uuid'),
            ],
            'address' => ['sometimes', 'string', 'min:5', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre comercial',
            'legal_name' => 'razón social',
            'address' => 'dirección',
        ];
    }
}
