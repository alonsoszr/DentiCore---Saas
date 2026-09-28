<?php

namespace App\Support\Time;

use App\Modules\Platform\Models\Tenant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Hora local de una clínica (SDD §2.1, §1.9; RF-009): los instantes se guardan en UTC y los
 * plazos del día (vencimiento de presupuestos, cierre de atenciones) se calculan en la zona
 * de la clínica (`tenants.timezone`, America/Lima por defecto).
 */
final class ClinicClock
{
    public const DEFAULT_TIMEZONE = 'America/Lima';

    private function __construct(private string $timezone) {}

    public static function for(?Tenant $tenant): self
    {
        $timezone = $tenant?->getAttribute('timezone');

        return new self(is_string($timezone) && $timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE);
    }

    public static function inTimezone(string $timezone): self
    {
        return new self($timezone);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Instante actual expresado en la zona de la clínica.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /**
     * Fecha civil local (Y-m-d) de un instante.
     */
    public function localDate(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone)->toDateString();
    }

    /**
     * Último segundo del día local (23:59:59 en la zona de la clínica), en UTC.
     */
    public function endOfLocalDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->endOfDay()->startOfSecond()->utc();
    }

    /**
     * Primer instante del día local, en UTC.
     */
    public function startOfLocalDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->startOfDay()->utc();
    }
}
