<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Models\ScheduleBlock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bloqueo de agenda y su impacto (CUS-44; RF-143, RF-144): odontólogo (o toda la clínica si no se
 * indica), inicio y fin; al crearlo, además el tipo y el motivo.
 */
class ScheduleBlockRequest extends FormRequest
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
        // La consulta de impacto (POST /schedule-blocks/impact) no lleva tipo ni motivo.
        $creating = ! str_ends_with($this->path(), '/impact');

        return [
            'dentist_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(ScheduleBlock::KINDS)],
            'reason' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:200'],
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
            'dentist_id' => 'odontólogo',
            'starts_at' => 'inicio',
            'ends_at' => 'fin',
            'kind' => 'tipo de bloqueo',
            'reason' => 'motivo',
            'decisions.*.action' => 'decisión',
            'decisions.*.new_starts_at' => 'nuevo inicio',
        ];
    }
}
