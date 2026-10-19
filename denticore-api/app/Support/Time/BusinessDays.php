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

    /**
     * Días hábiles que faltan desde `$today` hasta `$due` (CUS-64, paso 2): cuenta los días
     * hábiles posteriores a hoy hasta el plazo inclusive. Vencido, el número es negativo.
     */
    public static function remaining(DateTimeInterface $today, DateTimeInterface $due): int
    {
        $from = CarbonImmutable::instance($today)->startOfDay();
        $to = CarbonImmutable::instance($due)->startOfDay();

        if ($to->lessThan($from)) {
            return -self::remaining($to, $from);
        }

        $count = 0;
        for ($date = $from->addDay(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            if (! $date->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }
}
