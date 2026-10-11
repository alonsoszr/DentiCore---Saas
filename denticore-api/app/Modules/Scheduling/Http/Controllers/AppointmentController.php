<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\Dentists;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reserva, reprogramación y cancelación de citas (SDD §4.3.8, §4.5; CUS-47, CUS-48; RF-147,
 * RF-149). La recepción y el Administrador de Clínica las gestionan; la cita se resuelve con el
 * Global Scope de la clínica (otra clínica → 404).
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments, private Dentists $dentists) {}

    /**
     * CUS-47: `duration_min` ajusta la duración del tipo (FA-2).
     */
    #[ProblemResponse(409, 'El horario ya no está disponible (RN-46) o el paciente ya tiene otra cita en ese horario (RN-74)')]
    #[ProblemResponse(422, 'En el pasado (RN-48), fuera del horario o sobre un bloqueo (RN-47), tipo inactivo o paciente fallecido (RN-80)')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'dentist_id' => ['required', 'uuid'],
            'appointment_type_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'duration_min' => ['sometimes', 'integer', 'between:10,240', 'multiple_of:5'],
        ], [], ['patient_id' => 'paciente', 'dentist_id' => 'odontólogo', 'appointment_type_id' => 'tipo de cita', 'starts_at' => 'inicio', 'duration_min' => 'duración']);

        $patient = Patient::query()->where('uuid', $data['patient_id'])->first()
            ?? throw ValidationException::withMessages(['patient_id' => 'El paciente indicado no existe en la clínica.']);
        $dentist = $this->dentists->findOrNull($data['dentist_id']);
        if ($dentist === null || $dentist->status !== 'activo') {
            throw ValidationException::withMessages(['dentist_id' => 'El odontólogo indicado no existe o no está activo en la clínica.']);
        }
        $type = AppointmentType::query()->where('uuid', $data['appointment_type_id'])->first()
            ?? throw ValidationException::withMessages(['appointment_type_id' => 'El tipo de cita indicado no existe en la clínica.']);

        /** @var User $actor */
        $actor = $request->user();
        $appointment = $this->appointments->book([
            'patient' => $patient,
            'dentist' => $dentist,
            'type' => $type,
            'starts_at' => CarbonImmutable::parse($data['starts_at']),
            'duration_min' => (int) ($data['duration_min'] ?? $type->default_duration_min),
        ], $actor);

        return AppointmentResource::make($appointment->load(['patient', 'dentist', 'type']))->response()->setStatusCode(201);
    }

    /**
     * RF-149: nuevo inicio; la duración se conserva salvo que se indique otra.
     */
    #[ProblemResponse(409, 'La cita no está programada ni confirmada, o el nuevo horario ya no está disponible (RN-46, RN-74)')]
    #[ProblemResponse(422, 'En el pasado (RN-48), fuera del horario o sobre un bloqueo (RN-47)')]
    public function reschedule(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_min' => ['sometimes', 'integer', 'between:10,240', 'multiple_of:5'],
        ], [], ['starts_at' => 'inicio', 'duration_min' => 'duración']);

        $rescheduled = $this->appointments->reschedule(
            $appointment,
            CarbonImmutable::parse($data['starts_at']),
            isset($data['duration_min']) ? (int) $data['duration_min'] : null,
        );

        return AppointmentResource::make($rescheduled->load(['patient', 'dentist', 'type']));
    }

    /**
     * RF-149: cancelación con motivo (paciente, clínica u otro) y detalle opcional.
     */
    #[ProblemResponse(409, 'La cita no está programada ni confirmada')]
    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate([
            'cancel_reason' => ['required', Rule::in(['paciente', 'clinica', 'otro'])],
            'cancel_detail' => ['nullable', 'string', 'max:300'],
        ], [], ['cancel_reason' => 'motivo', 'cancel_detail' => 'detalle']);

        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make(
            $this->appointments->cancel($appointment, $data['cancel_reason'], $data['cancel_detail'] ?? null, $actor)->load(['patient', 'dentist', 'type']),
        );
    }
}
