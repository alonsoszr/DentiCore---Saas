<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentGate;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use App\Support\Tokens\OneTimeToken;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Citas (SDD §5.7; CUS-47 a CUS-49; RF-147, RF-149, RF-150; RN-46 a RN-49, RN-52, RN-74, RN-80;
 * estados de SRS §5.5.4). Toda escritura revalida las reglas dentro de la transacción; el EXCLUDE
 * de la base de datos resuelve las reservas simultáneas (DD-09): su 23P01 responde 409.
 *
 * Los correos `cita_*` salen solo si el paciente otorgó la finalidad (b) «notificaciones», tiene
 * correo y no falleció (RN-52, RN-80). El enlace de confirmación (token de un solo uso que vence
 * al inicio de la cita) viaja en `cita_creada` y, al reprogramar, en `cita_modificada`.
 */
class AppointmentService
{
    /** Estados que se pueden reprogramar, cancelar o confirmar (SRS §5.5.4). */
    private const PENDING_STATUSES = ['programada', 'confirmada'];

    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notifications,
        private OneTimeTokenService $tokens,
        private ConsentGate $consents,
    ) {}

    /**
     * Paso 5 de CUS-47: la cita queda `programada` con su origen.
     *
     * @param  array{patient: Patient, dentist: User, type: AppointmentType, starts_at: CarbonImmutable, duration_min: int}  $data
     *
     * @throws BusinessRuleException
     */
    public function book(array $data, User $actor, string $origin = 'clinica'): Appointment
    {
        $startsAt = $data['starts_at']->utc();
        $endsAt = $startsAt->addMinutes($data['duration_min']);

        if ($data['patient']->deceased_on !== null) {
            throw new BusinessRuleException('RN-80', 'No se reservan citas a un paciente fallecido.');
        }

        if (! $data['type']->is_active) {
            throw new BusinessRuleException('RN-47', 'El tipo de cita está inactivo.');
        }

        return DB::transaction(function () use ($data, $actor, $origin, $startsAt, $endsAt): Appointment {
            $this->ensureSchedulable($data['dentist']->id, $startsAt, $endsAt);

            $appointment = (new Appointment)->forceFill([
                'tenant_id' => TenantContext::id(),
                'patient_id' => $data['patient']->id,
                'dentist_id' => $data['dentist']->id,
                'appointment_type_id' => $data['type']->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'programada',
                'origin' => $origin,
                'reconfirmation_pending' => false,
                'created_by' => $actor->id,
            ]);
            $this->saveWithoutOverlap($appointment);

            $this->audit->record(AuditEvent::AppointmentCreated, $appointment, meta: ['origin' => $origin]);
            $this->notify($appointment, NotificationEvent::CitaCreada, withConfirmation: true);

            return $appointment;
        });
    }

    /**
     * RF-149: misma fila, nuevo horario. Una cita `confirmada` sigue `confirmada` y queda por
     * reconfirmar con un nuevo enlace (SRS §5.5.4).
     *
     * @throws BusinessRuleException
     */
    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, ?int $durationMin = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $startsAt, $durationMin): Appointment {
            $appointment = $this->lockPending($appointment, 'reprogramar');
            $startsAt = $startsAt->utc();
            $duration = $durationMin ?? (int) $appointment->starts_at->diffInMinutes($appointment->ends_at, true);
            $endsAt = $startsAt->addMinutes($duration);
            $this->ensureSchedulable($appointment->dentist_id, $startsAt, $endsAt);

            $wasConfirmed = $appointment->status === 'confirmada';
            $appointment->forceFill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reconfirmation_pending' => $wasConfirmed,
                'confirmed_at' => null,
                'confirmation_channel' => null,
            ]);
            $this->saveWithoutOverlap($appointment);
            $this->tokens->invalidateFor($appointment, TokenPurpose::AppointmentConfirmation);

            $this->audit->record(AuditEvent::AppointmentRescheduled, $appointment, ['starts_at', 'ends_at']);
            $this->notify($appointment, NotificationEvent::CitaModificada, withConfirmation: true);

            return $appointment;
        });
    }

    /**
     * RF-149: la cancelación exige motivo y libera la franja.
     *
     * @throws BusinessRuleException
     */
    public function cancel(Appointment $appointment, string $reason, ?string $detail, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason, $detail, $actor): Appointment {
            $appointment = $this->lockPending($appointment, 'cancelar');
            $appointment->forceFill([
                'status' => 'cancelada',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'cancel_detail' => $detail === null || trim($detail) === '' ? null : trim($detail),
                'reconfirmation_pending' => false,
            ])->save();
            $this->tokens->invalidateFor($appointment, TokenPurpose::AppointmentConfirmation);

            $this->audit->record(AuditEvent::AppointmentCancelled, $appointment, ['status'], ['cancel_reason' => $reason]);
            $this->notify($appointment, NotificationEvent::CitaCancelada, withConfirmation: false);

            return $appointment;
        });
    }

    /**
     * RF-150: confirmación por el personal (teléfono) o por el enlace. Confirmar una cita ya
     * confirmada sin reconfirmación pendiente no cambia nada.
     *
     * @throws BusinessRuleException
     */
    public function confirm(Appointment $appointment, string $channel): Appointment
    {
        return DB::transaction(function () use ($appointment, $channel): Appointment {
            $appointment = $this->lockPending($appointment, 'confirmar');

            if ($appointment->status === 'confirmada' && ! $appointment->reconfirmation_pending) {
                return $appointment;
            }

            $appointment->forceFill([
                'status' => 'confirmada',
                'confirmed_at' => now(),
                'confirmation_channel' => $channel,
                'reconfirmation_pending' => false,
            ])->save();
            $this->tokens->invalidateFor($appointment, TokenPurpose::AppointmentConfirmation);

            $this->audit->record(AuditEvent::AppointmentConfirmed, $appointment, ['status'], ['channel' => $channel]);

            return $appointment;
        });
    }

    /**
     * Confirmación desde el enlace del correo, sin sesión (CUS-49). El token se consume.
     *
     * @throws BusinessRuleException
     */
    public function confirmByToken(OneTimeToken $token): Appointment
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::query()->whereKey($token->tokenable_id)->firstOrFail();
        $confirmed = $this->confirm($appointment, 'enlace');
        $this->tokens->consume($token);

        return $confirmed;
    }

    /**
     * CUS-52, RN-52: recordatorio por correo 24 h antes, una sola vez por cita. Lleva el enlace de
     * confirmación si la cita aún no está confirmada o espera la reconfirmación.
     */
    public function remind(Appointment $appointment): void
    {
        $dedupeKey = "recordatorio:{$appointment->uuid}";

        if (Notification::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }

        $needsConfirmation = $appointment->status === 'programada' || $appointment->reconfirmation_pending;
        $this->notify($appointment, NotificationEvent::CitaRecordatorio, $needsConfirmation, $dedupeKey);
    }

    /**
     * RN-47 y RN-48: inicio futuro, dentro de una franja vigente del odontólogo en la hora local
     * de la clínica y sin cruzarse con un bloqueo del odontólogo o de la clínica.
     *
     * @throws BusinessRuleException
     */
    private function ensureSchedulable(int $dentistId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $clock = ClinicClock::for(TenantContext::tenantOrFail());

        if ($startsAt <= $clock->now()) {
            throw new BusinessRuleException('RN-48', 'La cita no puede empezar en el pasado.');
        }

        $start = $startsAt->setTimezone($clock->timezone());
        $end = $endsAt->setTimezone($clock->timezone());
        $date = $start->toDateString();

        $insideSchedule = $start->isSameDay($end) && WorkSchedule::query()
            ->where('dentist_id', $dentistId)
            ->where('weekday', $start->dayOfWeekIso)
            ->where('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->where('start_time', '<=', $start->format('H:i:s'))
            ->where('end_time', '>=', $end->format('H:i:s'))
            ->exists();

        if (! $insideSchedule) {
            throw new BusinessRuleException('RN-47', 'El horario está fuera del horario laboral del odontólogo.');
        }

        $blocked = ScheduleBlock::query()
            ->where(fn ($query) => $query->whereNull('dentist_id')->orWhere('dentist_id', $dentistId))
            ->whereRaw("period && tstzrange(?, ?, '[)')", [$startsAt->format(DATE_ATOM), $endsAt->format(DATE_ATOM)])
            ->exists();

        if ($blocked) {
            throw new BusinessRuleException('RN-47', 'El horario se cruza con un bloqueo de la agenda.');
        }
    }

    /**
     * DD-09: el EXCLUDE rechaza la cita solapada aunque dos solicitudes lleguen a la vez.
     *
     * @throws BusinessRuleException
     */
    private function saveWithoutOverlap(Appointment $appointment): void
    {
        try {
            DB::transaction(fn () => $appointment->save());
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }

            if (str_contains($exception->getMessage(), 'appointments_no_overlap_patient')) {
                throw new BusinessRuleException('RN-74', 'El paciente ya tiene otra cita en ese horario.', status: 409);
            }

            throw new BusinessRuleException('RN-46', 'El horario ya no está disponible.', status: 409);
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function lockPending(Appointment $appointment, string $action): Appointment
    {
        /** @var Appointment $locked */
        $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, self::PENDING_STATUSES, true)) {
            throw new BusinessRuleException('RF-149', "No se puede {$action} una cita en estado {$locked->status}.", status: 409);
        }

        return $locked;
    }

    /**
     * RN-52 y RN-80: aviso por correo al paciente con la finalidad (b), sin datos clínicos.
     */
    private function notify(Appointment $appointment, NotificationEvent $event, bool $withConfirmation, ?string $dedupeKey = null): void
    {
        $appointment->loadMissing(['patient', 'dentist', 'type']);
        $patient = $appointment->patient;

        if ($patient->deceased_on !== null || $patient->email === null || ! $this->consents->allows($patient, 'notificaciones')) {
            return;
        }

        $clinic = TenantContext::tenantOrFail();
        $start = $appointment->starts_at->copy()->setTimezone(ClinicClock::for($clinic)->timezone());
        $links = [];

        if ($withConfirmation) {
            $token = $this->tokens->issue($appointment, TokenPurpose::AppointmentConfirmation, $appointment->starts_at)['token'];
            $links['confirm'] = rtrim((string) config('app.spa_url'), '/')."/c/{$clinic->slug}/confirmar/{$token}";
        }

        $this->notifications->sendEmail($event, $patient, data: [
            'date' => $start->format('d/m/Y'),
            'time' => $start->format('H:i'),
            'dentist' => $appointment->dentist->name,
            'type' => $appointment->type->name,
        ], links: $links, dedupeKey: $dedupeKey ?? "{$event->value}:{$appointment->uuid}:{$appointment->starts_at->getTimestamp()}");
    }
}
