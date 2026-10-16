<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Services\AvailabilityCalculator;
use App\Modules\Scheduling\Services\Dentists;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Disponibilidad de la agenda (SDD §4.3.8, §5.7; CUS-46; RF-146): inicios libres agrupados por
 * odontólogo (SRS §11.13, pasos 2 y 3), para un tipo de cita o una duración ajustada por el
 * personal (FA-2), entre dos fechas locales de la clínica y, si se indica, sin cruzarse con las
 * citas activas del paciente (RN-74).
 */
class AvailabilityController extends Controller
{
    /** Días que abarca como máximo una consulta. */
    private const MAX_DAYS = 31;

    public function __construct(private AvailabilityCalculator $calculator, private Dentists $dentists) {}

    #[ProblemResponse(422, 'Tipo de cita inactivo, duración fuera de 10–240 min o no múltiplo de 5, o rango de fechas inválido (RN-47, RN-48)')]
    public function index(Request $request): JsonResponse
    {
        $today = ClinicClock::for(TenantContext::tenant())->now()->toDateString();
        $data = $request->validate([
            'appointment_type_id' => ['required_without:duration_min', 'uuid'],
            'duration_min' => ['sometimes', 'integer', 'between:10,240', 'multiple_of:5'],
            'dentist_id' => ['sometimes', 'uuid'],
            'patient_id' => ['sometimes', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}"],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], [
            'appointment_type_id' => 'tipo de cita', 'duration_min' => 'duración', 'dentist_id' => 'odontólogo',
            'patient_id' => 'paciente', 'from' => 'desde', 'to' => 'hasta',
        ]);

        if (now()->parse($data['from'])->diffInDays(now()->parse($data['to'])) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Consulte como máximo '.self::MAX_DAYS.' días a la vez.']);
        }

        $duration = $this->duration($data);
        $dentists = $this->dentists->active($data['dentist_id'] ?? null);

        if (isset($data['dentist_id']) && $dentists->isEmpty()) {
            throw ValidationException::withMessages(['dentist_id' => 'El odontólogo indicado no existe o no está activo en la clínica.']);
        }

        $patientId = null;
        if (isset($data['patient_id'])) {
            $patientId = Patient::query()->where('uuid', $data['patient_id'])->value('id')
                ?? throw ValidationException::withMessages(['patient_id' => 'El paciente indicado no existe en la clínica.']);
        }

        return response()->json([
            'data' => $this->calculator->byDentist($dentists, $duration, $data['from'], $data['to'], $patientId),
        ]);
    }

    /**
     * FA-2: la duración ajustada por el personal manda sobre la del tipo; el tipo debe estar activo.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function duration(array $data): int
    {
        if (isset($data['appointment_type_id'])) {
            $type = AppointmentType::query()->where('uuid', $data['appointment_type_id'])->first();

            if ($type === null || ! $type->is_active) {
                throw ValidationException::withMessages(['appointment_type_id' => 'El tipo de cita no existe o está inactivo.']);
            }

            return isset($data['duration_min']) ? (int) $data['duration_min'] : $type->default_duration_min;
        }

        return (int) $data['duration_min'];
    }
}
