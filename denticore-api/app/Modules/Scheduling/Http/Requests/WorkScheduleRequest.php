<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reemplazo del horario laboral de un odontólogo desde una fecha (CUS-44; RF-142): franjas por día
 * ISO (1 = lunes) con hora de inicio y fin. Una lista vacía deja al odontólogo sin horario.
 */
class WorkScheduleRequest extends FormRequest
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
        $today = ClinicClock::for(TenantContext::tenant())->now()->toDateString();

        return [
            'valid_from' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}"],
            'slots' => ['present', 'array', 'max:50'],
            'slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i', 'after:slots.*.start_time'],
            'decisions' => ['sometimes', 'array'],
            'decisions.*.appointment_id' => ['required', 'uuid', 'distinct'],
            'decisions.*.action' => ['required', Rule::in(['reprogramar', 'cancelar'])],
            'decisions.*.new_starts_at' => ['required_if:decisions.*.action,reprogramar', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'valid_from' => 'vigente desde',
            'slots' => 'franjas',
            'slots.*.weekday' => 'día',
            'slots.*.start_time' => 'hora de inicio',
            'slots.*.end_time' => 'hora de fin',
            'decisions.*.action' => 'decisión',
            'decisions.*.new_starts_at' => 'nuevo inicio',
        ];
    }
}
