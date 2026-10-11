<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Disponibilidad de la agenda (SDD §5.7; CUS-46; RF-146, DD-24, RN-46 a RN-48, RN-74): inicios
 * cada 15 min desde el comienzo de cada franja vigente cuyo intervalo cabe en la franja, sin
 * bloqueos del odontólogo ni de la clínica y sin citas activas del odontólogo o del paciente. Los
 * inicios ya pasados no se ofrecen; en el portal, tampoco los que empiezan antes de 2 h.
 *
 * `slots()` hace una consulta por tabla (franjas, bloqueos y citas, con el índice GIST de
 * tstzrange) y `candidates()` calcula sin acceso a la base de datos.
 */
class AvailabilityCalculator
{
    public const STEP_MINUTES = 15;

    public const PORTAL_NOTICE_HOURS = 2;

    /**
     * @param  Collection<int, User>  $dentists
     * @return array<int, list<CarbonImmutable>> Inicios en UTC por id de odontólogo.
     */
    public function slots(Collection $dentists, int $durationMin, string $from, string $to, ?int $patientId = null, bool $portal = false): array
    {
        $clock = ClinicClock::for(TenantContext::tenantOrFail());
        $rangeStart = $clock->startOfLocalDay($from);
        $rangeEnd = $clock->startOfLocalDay($to)->addDay();
        $range = [$rangeStart->format(DATE_ATOM), $rangeEnd->format(DATE_ATOM)];
        $ids = $dentists->pluck('id')->all();

        $schedules = WorkSchedule::query()
            ->whereIn('dentist_id', $ids)
            ->whereRaw("validity && daterange(?::date, ?::date, '[]')", [$from, $to])
            ->get(['dentist_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_until'])
            ->groupBy('dentist_id');

        $blocks = ScheduleBlock::query()
            ->where(fn ($query) => $query->whereNull('dentist_id')->orWhereIn('dentist_id', $ids))
            ->whereRaw("period && tstzrange(?, ?, '[)')", $range)
            ->get(['dentist_id', 'period']);

        $appointments = Appointment::query()
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereIn('dentist_id', $ids)
                ->when($patientId !== null, fn ($inner) => $inner->orWhere('patient_id', $patientId)))
            ->whereRaw("period && tstzrange(?, ?, '[)')", $range)
            ->get(['dentist_id', 'patient_id', 'starts_at', 'ends_at']);

        $now = $clock->now();
        $notBefore = $portal ? $now->addHours(self::PORTAL_NOTICE_HOURS) : $now;
        $result = [];

        foreach ($ids as $id) {
            $busy = [];

            foreach ($blocks as $block) {
                if ($block->dentist_id === null || $block->dentist_id === $id) {
                    $busy[] = [$block->startsAt()->toImmutable(), $block->endsAt()->toImmutable()];
                }
            }

            foreach ($appointments as $appointment) {
                if ($appointment->dentist_id === $id || ($patientId !== null && $appointment->patient_id === $patientId)) {
                    $busy[] = [$appointment->starts_at->toImmutable(), $appointment->ends_at->toImmutable()];
                }
            }

            $slots = ($schedules->get($id) ?? collect())->map(fn (WorkSchedule $schedule) => [
                'weekday' => $schedule->weekday,
                'start_time' => substr($schedule->start_time, 0, 5),
                'end_time' => substr($schedule->end_time, 0, 5),
                'valid_from' => $schedule->valid_from->toDateString(),
                'valid_until' => $schedule->valid_until?->toDateString(),
            ])->values()->all();

            $result[$id] = self::candidates($slots, $busy, $durationMin, $from, $to, $clock->timezone(), $notBefore);
        }

        return $result;
    }

    /**
     * Inicios disponibles entre `$from` y `$to` (fechas locales, inclusive).
     *
     * @param  list<array{weekday: int, start_time: string, end_time: string, valid_from: string, valid_until: string|null}>  $slots
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy  Intervalos `[inicio, fin)` ocupados.
     * @return list<CarbonImmutable> Inicios en UTC, ordenados.
     */
    public static function candidates(array $slots, array $busy, int $durationMin, string $from, string $to, string $timezone, CarbonImmutable $notBefore): array
    {
        $starts = [];

        for ($day = CarbonImmutable::parse($from, $timezone); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ($slots as $slot) {
                $valid = $slot['weekday'] === $day->dayOfWeekIso
                    && $slot['valid_from'] <= $date
                    && ($slot['valid_until'] === null || $date <= $slot['valid_until']);

                if (! $valid) {
                    continue;
                }

                $slotEnd = CarbonImmutable::parse("{$date} {$slot['end_time']}", $timezone);

                for ($start = CarbonImmutable::parse("{$date} {$slot['start_time']}", $timezone); $start->addMinutes($durationMin) <= $slotEnd; $start = $start->addMinutes(self::STEP_MINUTES)) {
                    $end = $start->addMinutes($durationMin);

                    if ($start <= $notBefore || self::overlapsAny($start, $end, $busy)) {
                        continue;
                    }

                    $starts[] = $start->utc();
                }
            }
        }

        usort($starts, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $starts;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    private static function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $busyStart < $end) {
                return true;
            }
        }

        return false;
    }
}
