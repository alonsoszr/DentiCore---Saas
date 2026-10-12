<?php

namespace App\Modules\Risk\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Acción con que se reconoce una alerta de riesgo alto (SDD §2.11 `risk_alerts`; RN-65, RF-171):
 * plan preventivo, cita de control o justificación de 20 a 1000 caracteres.
 */
class AcknowledgeRiskAlertRequest extends FormRequest
{
    /** Solo el odontólogo destinatario; antes de validar, para que una alerta ajena responda 404. */
    public function authorize(): Response
    {
        return Gate::inspect('acknowledge', $this->route('alert'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['plan_preventivo', 'cita_control', 'justificacion'])],
            'treatment_plan_id' => ['required_if:action,plan_preventivo', 'nullable', 'uuid'],
            'appointment_id' => ['required_if:action,cita_control', 'nullable', 'uuid'],
            'justification' => ['required_if:action,justificacion', 'nullable', 'string', 'min:20', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'acción',
            'treatment_plan_id' => 'plan preventivo',
            'appointment_id' => 'cita de control',
            'justification' => 'justificación',
        ];
    }
}
