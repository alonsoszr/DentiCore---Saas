<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\AppointmentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un tipo de cita (CUS-45; RF-145, RN-47): nombre único en la clínica, duración
 * de 10 a 240 min en múltiplos de 5, color `#RRGGBB` y visibilidad en el portal. En la edición los
 * campos son opcionales.
 */
class AppointmentTypeRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $type = $this->route('type');

        return [
            'name' => [$required, 'string', 'max:80', Rule::unique('appointment_types', 'name')
                ->where('tenant_id', TenantContext::id())
                ->ignore($type instanceof AppointmentType ? $type->id : null)],
            'default_duration_min' => [$required, 'integer', 'between:10,240', 'multiple_of:5'],
            'color' => [$required, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'portal_visible' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'default_duration_min' => 'duración',
            'color' => 'color',
            'portal_visible' => 'visible en el portal',
            'is_active' => 'activo',
        ];
    }
}
