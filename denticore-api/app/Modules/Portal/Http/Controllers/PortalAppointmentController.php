<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Services\PortalAppointmentRules;
use App\Modules\Portal\Services\PortalSubjectResolver;
use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Modules\Scheduling\Services\AvailabilityCalculator;
use App\Modules\Scheduling\Services\Dentists;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Citas en el portal (SDD §4.3.10, §5.7; CUS-46 a CUS-49, CUS-77; RF-146, RF-148 a RF-150, RN-48,
 * RN-49). La reserva usa solo tipos visibles en el portal y la duración del tipo; `portal.subject`
 * da 404 para las citas de otro paciente. Cancelar desde el portal registra el motivo «paciente»:
 * el SDD no define el cuerpo y se registra como vacío de MS-06.
 */
class PortalAppointmentController extends Controller
{
    private const RELATIONS = ['patient', 'dentist', 'type'];

    public function __construct(
        private AppointmentService $appointments,
        private PortalAppointmentRules $rules,
        private Dentists $dentists,
    ) {}

    /**
     * CUS-77: las citas del paciente, de la más reciente a la más antigua.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return AppointmentResource::collection(
            Appointment::query()->where('patient_id', $patient->id)->with(self::RELATIONS)->orderByDesc('starts_at')->get(),
        );
    }

    /**
     * CUS-46: inicios libres de un tipo visible en el portal, desde 2 horas después (RN-48).
     */
    #[ProblemResponse(422, 'Tipo no disponible en el portal o rango de fechas inválido (RN-48)')]
    public function availability(Request $request, AvailabilityCalculator $calculator, PortalSubjectResolver $subjects): JsonResponse
    {
        $today = ClinicClock::for(TenantContext::tenantOrFail())->now()->toDateString();
        $data = $request->validate([
            'appointment_type_id' => ['required', 'uuid'],
            'dentist_id' => ['sometimes', 'uuid'],
            'patient_id' => ['sometimes', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}"],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse($today)->addDays(30)->toDateString()],
        ], [], ['appointment_type_id' => 'tipo de cita', 'dentist_id' => 'odontólogo', 'patient_id' => 'paciente', 'from' => 'desde', 'to' => 'hasta']);

        $type = $this->portalType($data['appointment_type_id']);
        /** @var User $user */
        $user = $request->user();
        // RN-74: con el paciente indicado, sin cruzarse con sus citas; solo un sujeto de la cuenta.
        $patientId = null;
        if (isset($data['patient_id'])) {
            $subject = $subjects->for($user)->first(fn (Patient $patient): bool => $patient->uuid === $data['patient_id']);
            abort_if($subject === null, 404);
            $patientId = $subject->id;
        }

        return response()->json([
            'data' => $calculator->byDentist(
                $this->dentists->active($data['dentist_id'] ?? null),
                $type->default_duration_min,
                $data['from'],
                $data['to'],
                $patientId,
                portal: true,
            ),
        ]);
    }

    /**
     * CUS-47 FA-1: reserva con la duración del tipo; la cita queda con origen `portal`.
     */
    #[ProblemResponse(409, 'El horario ya no está disponible (RN-46) o el paciente ya tiene otra cita en ese horario (RN-74)')]
    #[ProblemResponse(422, 'Menos de 2 horas de anticipación o 2 citas autoagendadas activas (RN-48), fuera del horario (RN-47)')]
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'appointment_type_id' => ['required', 'uuid'],
            'dentist_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
        ], [], ['appointment_type_id' => 'tipo de cita', 'dentist_id' => 'odontólogo', 'starts_at' => 'inicio']);

        $type = $this->portalType($data['appointment_type_id']);
        $dentist = $this->dentists->active($data['dentist_id'])->first()
            ?? throw ValidationException::withMessages(['dentist_id' => 'El odontólogo indicado no existe o no está activo en la clínica.']);
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        /** @var User $actor */
        $actor = $request->user();

        // Bloquear al paciente serializa sus reservas simultáneas y mantiene el límite de RN-48.
        $appointment = DB::transaction(function () use ($patient, $dentist, $type, $startsAt, $actor): Appointment {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();
            $this->rules->ensureBookable($patient, $startsAt);

            return $this->appointments->book([
                'patient' => $patient,
                'dentist' => $dentist,
                'type' => $type,
                'starts_at' => $startsAt,
                'duration_min' => $type->default_duration_min,
            ], $actor, 'portal');
        });

        return AppointmentResource::make($appointment->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * CUS-48: nuevo inicio con la misma duración, hasta `portal_cancel_hours` antes (RN-49).
     */
    #[ProblemResponse(409, 'La cita no está programada ni confirmada, o el nuevo horario ya no está disponible (RN-46, RN-74)')]
    #[ProblemResponse(422, 'Fuera del plazo del portal (RN-49) o sin la anticipación mínima (RN-48)')]
    public function reschedule(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate(['starts_at' => ['required', 'date']], [], ['starts_at' => 'inicio']);
        $startsAt = CarbonImmutable::parse($data['starts_at']);

        $this->rules->ensureChangeable($appointment);
        $this->rules->ensureNotice($startsAt);

        return AppointmentResource::make($this->appointments->reschedule($appointment, $startsAt)->load(self::RELATIONS));
    }

    /**
     * CUS-48: cancelación por el paciente con detalle opcional, hasta `portal_cancel_hours` antes.
     */
    #[ProblemResponse(409, 'La cita no está programada ni confirmada')]
    #[ProblemResponse(422, 'Fuera del plazo del portal (RN-49)')]
    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validate(['cancel_detail' => ['nullable', 'string', 'max:300']], [], ['cancel_detail' => 'detalle']);
        $this->rules->ensureChangeable($appointment);
        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make(
            $this->appointments->cancel($appointment, 'paciente', $data['cancel_detail'] ?? null, $actor)->load(self::RELATIONS),
        );
    }

    /**
     * CUS-49, RF-150: confirmación desde el portal.
     */
    #[ProblemResponse(409, 'La cita no está programada ni confirmada')]
    public function confirm(Appointment $appointment): AppointmentResource
    {
        return AppointmentResource::make($this->appointments->confirm($appointment, 'portal')->load(self::RELATIONS));
    }

    /**
     * CUS-46: solo los tipos activos habilitados para el portal.
     *
     * @throws ValidationException
     */
    private function portalType(string $uuid): AppointmentType
    {
        $type = AppointmentType::query()->where('uuid', $uuid)->first();

        if ($type === null || ! $type->is_active || ! $type->portal_visible) {
            throw ValidationException::withMessages(['appointment_type_id' => 'El tipo de cita no está disponible en el portal.']);
        }

        return $type;
    }
}
