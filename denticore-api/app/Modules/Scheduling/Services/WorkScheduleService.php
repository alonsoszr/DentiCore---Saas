<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horario laboral de un odontólogo (CUS-44; RF-142, RF-144, RN-82, DI-09).
 */
class WorkScheduleService
{
    public function __construct(private AffectedAppointments $affected) {}

    /**
     * Franjas vigentes hoy o más adelante, por día y hora.
     *
     * @return Collection<int, WorkSchedule>
     */
    public function current(User $dentist): Collection
    {
        $today = ClinicClock::for(TenantContext::tenantOrFail())->now()->toDateString();

        return WorkSchedule::query()
            ->where('dentist_id', $dentist->id)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderBy('valid_from')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Reemplaza el horario desde `valid_from`: las franjas anteriores terminan el día previo y las
     * que empezaban después se eliminan. Franjas solapadas del mismo día → 422 (RF-142). Las citas
     * activas que el nuevo horario deja fuera exigen una decisión por cita (RN-82), que se aplica
     * en la misma transacción después de guardar el horario nuevo.
     *
     * @param  array{valid_from: string, slots: list<array{weekday: int, start_time: string, end_time: string}>}  $data
     * @param  list<array{appointment_id: string, action: string, new_starts_at?: string|null}>  $decisions
     * @return Collection<int, WorkSchedule>
     *
     * @throws BusinessRuleException|ValidationException
     */
    public function replace(User $dentist, array $data, User $actor, array $decisions = []): Collection
    {
        $slots = array_map(fn (array $slot) => [
            'weekday' => (int) $slot['weekday'],
            'start_time' => substr($slot['start_time'], 0, 5),
            'end_time' => substr($slot['end_time'], 0, 5),
        ], $data['slots']);
        $this->ensureNoOverlap($slots);

        $clock = ClinicClock::for(TenantContext::tenantOrFail());
        $validFrom = $data['valid_from'];

        return DB::transaction(function () use ($dentist, $slots, $validFrom, $clock, $actor, $decisions): Collection {
            // Serializa los cambios de horario del mismo odontólogo.
            User::query()->whereKey($dentist->id)->lockForUpdate()->first();

            $from = $clock->startOfLocalDay($validFrom);
            $now = $clock->now();
            $resolved = $this->affected->resolve($this->affected->outsideSlots($dentist->id, $from->greaterThan($now) ? $from : $now, $slots), $decisions);

            $previousDay = Carbon::parse($validFrom)->subDay()->toDateString();
            WorkSchedule::query()->where('dentist_id', $dentist->id)->where('valid_from', '>=', $validFrom)->delete();
            WorkSchedule::query()->where('dentist_id', $dentist->id)
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $validFrom))
                ->update(['valid_until' => $previousDay, 'updated_at' => now()]);

            try {
                foreach ($slots as $slot) {
                    (new WorkSchedule)->forceFill([
                        'tenant_id' => $dentist->tenant_id,
                        'dentist_id' => $dentist->id,
                        ...$slot,
                        'valid_from' => $validFrom,
                    ])->save();
                }
            } catch (QueryException $exception) {
                if ($exception->getCode() === '23P01') {
                    throw ValidationException::withMessages(['slots' => 'Las franjas del mismo día no pueden solaparse.']);
                }

                throw $exception;
            }

            $this->affected->apply($resolved, $actor, 'Cambio del horario laboral del odontólogo');

            return $this->current($dentist);
        });
    }

    /**
     * RF-142: el EXCLUDE de la base de datos es la última barrera; aquí se indica la franja.
     *
     * @param  list<array{weekday: int, start_time: string, end_time: string}>  $slots
     *
     * @throws ValidationException
     */
    private function ensureNoOverlap(array $slots): void
    {
        foreach ($slots as $index => $slot) {
            foreach ($slots as $other => $candidate) {
                $overlaps = $other < $index
                    && $candidate['weekday'] === $slot['weekday']
                    && $candidate['start_time'] < $slot['end_time']
                    && $slot['start_time'] < $candidate['end_time'];

                if ($overlaps) {
                    throw ValidationException::withMessages([
                        "slots.{$index}.start_time" => 'La franja se solapa con otra del mismo día.',
                    ]);
                }
            }
        }
    }
}
