<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Citas activas que un bloqueo o un cambio de horario dejaría fuera de la agenda (CUS-44; RF-144,
 * RN-82). Sin una decisión por cada una (reprogramar o cancelar, TASK-068) la operación responde
 * 422 y lista las citas en `errors` con la clave `decisions.{uuid}`.
 */
class AffectedAppointments
{
    public function __construct(private AppointmentService $appointments) {}

    /**
     * Citas activas que se cruzan con `[$startsAt, $endsAt)`, de un odontólogo o de toda la clínica.
     *
     * @return Collection<int, Appointment>
     */
    public function overlapping(?int $dentistId, DateTimeInterface $startsAt, DateTimeInterface $endsAt): Collection
    {
        return Appointment::query()
            ->with(['patient', 'dentist', 'type'])
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->when($dentistId !== null, fn ($query) => $query->where('dentist_id', $dentistId))
            ->whereRaw('period && tstzrange(?, ?, \'[)\')', [$startsAt->format(DATE_ATOM), $endsAt->format(DATE_ATOM)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Citas activas del odontólogo desde `$from` que no caben en ninguna de las franjas nuevas de
     * su día de la semana, en la hora local de la clínica.
     *
     * @param  list<array{weekday: int, start_time: string, end_time: string}>  $slots
     * @return Collection<int, Appointment>
     */
    public function outsideSlots(int $dentistId, DateTimeInterface $from, array $slots): Collection
    {
        $timezone = ClinicClock::for(TenantContext::tenantOrFail())->timezone();

        return Appointment::query()
            ->with(['patient', 'dentist', 'type'])
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->where('dentist_id', $dentistId)
            ->where('starts_at', '>=', $from)
            ->orderBy('starts_at')
            ->get()
            ->filter(function (Appointment $appointment) use ($slots, $timezone): bool {
                $start = $appointment->starts_at->copy()->setTimezone($timezone);
                $end = $appointment->ends_at->copy()->setTimezone($timezone);

                foreach ($slots as $slot) {
                    $fits = $slot['weekday'] === $start->dayOfWeekIso
                        && $start->isSameDay($end)
                        && $start->format('H:i') >= substr($slot['start_time'], 0, 5)
                        && $end->format('H:i') <= substr($slot['end_time'], 0, 5);

                    if ($fits) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * RN-82: una decisión por cada cita afectada (reprogramar con su nuevo inicio, o cancelar) y
     * ninguna para citas que el cambio no afecta. Se valida antes de escribir nada.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @param  list<array{appointment_id: string, action: string, new_starts_at?: string|null}>  $decisions
     * @return list<array{appointment: Appointment, action: string, new_starts_at: CarbonImmutable|null}>
     *
     * @throws BusinessRuleException
     */
    public function resolve(Collection $appointments, array $decisions): array
    {
        $byUuid = collect($decisions)->keyBy('appointment_id');
        $affected = $appointments->keyBy('uuid');
        $errors = [];

        foreach ($byUuid as $uuid => $decision) {
            if (! $affected->has($uuid)) {
                $errors["decisions.{$uuid}"] = ['La cita no está afectada por este cambio.'];
            } elseif ($decision['action'] === 'reprogramar' && empty($decision['new_starts_at'])) {
                $errors["decisions.{$uuid}"] = ['Indique el nuevo inicio de la cita.'];
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleException('RN-82', 'Revise las decisiones sobre las citas afectadas.', $errors);
        }

        $this->ensureNone($appointments->reject(fn (Appointment $appointment) => $byUuid->has($appointment->uuid))->values());

        return $appointments->map(fn (Appointment $appointment) => [
            'appointment' => $appointment,
            'action' => $byUuid[$appointment->uuid]['action'],
            'new_starts_at' => empty($byUuid[$appointment->uuid]['new_starts_at'])
                ? null
                : CarbonImmutable::parse($byUuid[$appointment->uuid]['new_starts_at']),
        ])->values()->all();
    }

    /**
     * Aplica las decisiones ya validadas con AppointmentService, dentro de la transacción del
     * bloqueo o del horario: si una reprogramación choca (409), no queda nada aplicado. Cada
     * paciente con la finalidad (b) recibe `cita_modificada` o `cita_cancelada`.
     *
     * @param  list<array{appointment: Appointment, action: string, new_starts_at: CarbonImmutable|null}>  $resolved
     *
     * @throws BusinessRuleException
     */
    public function apply(array $resolved, User $actor, string $cancelDetail): void
    {
        foreach ($resolved as $decision) {
            if ($decision['action'] === 'cancelar') {
                $this->appointments->cancel($decision['appointment'], 'clinica', $cancelDetail, $actor);
            } elseif ($decision['new_starts_at'] !== null) {
                $this->appointments->reschedule($decision['appointment'], $decision['new_starts_at']);
            }
        }
    }

    /**
     * RN-82: sin decisiones, una cita afectada impide la operación.
     *
     * @param  Collection<int, Appointment>  $appointments
     *
     * @throws BusinessRuleException
     */
    public function ensureNone(Collection $appointments): void
    {
        if ($appointments->isEmpty()) {
            return;
        }

        $timezone = ClinicClock::for(TenantContext::tenantOrFail())->timezone();
        $errors = [];

        foreach ($appointments as $appointment) {
            $when = $appointment->starts_at->copy()->setTimezone($timezone)->format('d/m/Y H:i');
            $errors["decisions.{$appointment->uuid}"] = ["La cita del {$when} necesita una decisión: reprogramar o cancelar."];
        }

        throw new BusinessRuleException(
            'RN-82',
            "Hay {$appointments->count()} cita(s) activa(s) afectada(s): indique qué hacer con cada una.",
            $errors,
        );
    }
}
