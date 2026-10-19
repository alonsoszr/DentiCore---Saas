<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Días hábiles de los plazos ARCO (SDD §5.11; DD-26, RN-69, CA-64.1): lunes a viernes, sin feriados,
 * contados desde el día hábil siguiente a la recepción. Trabaja sobre la fecha local de la clínica
 * (ClinicClock::localDate) y devuelve el inicio de ese día.
 */
final class BusinessDays
{
    public static function addWorkingDays(DateTimeInterface $receivedOn, int $days): CarbonImmutable
    {
        $date = CarbonImmutable::instance($receivedOn)->startOfDay();

        for ($counted = 0; $counted < $days;) {
            $date = $date->addDay();

            if (! $date->isWeekend()) {
                $counted++;
            }
        }

        return $date;
    }
}
