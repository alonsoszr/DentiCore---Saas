<?php

namespace App\Modules\Portal\Services;

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Services\ClinicSettingsService;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AvailabilityCalculator;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;

/**
 * Límites del portal sobre las citas (SDD §5.7; RN-48, RN-49; CUS-47 FA-1, CUS-48): el paciente
 * reserva con 2 horas de anticipación y como máximo 2 citas activas autoagendadas, y reprograma o
 * cancela hasta `portal_cancel_hours` antes del inicio. Después solo la clínica puede hacerlo.
 */
class PortalAppointmentRules
{
    public const MAX_SELF_BOOKED = 2;

    public function __construct(private ClinicSettingsService $settings) {}

    /**
     * RN-48 (SRS §11.13 FE-3, FE-4). Va dentro de la transacción que bloquea al paciente.
     *
     * @throws BusinessRuleException
     */
    public function ensureBookable(Patient $patient, CarbonImmutable $startsAt): void
    {
        $this->ensureNotice($startsAt);

        $selfBooked = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('origin', 'portal')
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->count();

        if ($selfBooked >= self::MAX_SELF_BOOKED) {
            throw new BusinessRuleException('RN-48', 'Ya tiene '.self::MAX_SELF_BOOKED.' citas activas reservadas por el portal.');
        }
    }

    /**
     * RN-48: el nuevo inicio de una cita reprogramada desde el portal respeta la anticipación.
     *
     * @throws BusinessRuleException
     */
    public function ensureNotice(CarbonImmutable $startsAt): void
    {
        $earliest = ClinicClock::for(TenantContext::tenantOrFail())->now()->addHours(AvailabilityCalculator::PORTAL_NOTICE_HOURS);

        // Como la disponibilidad del portal: el inicio debe ser posterior a ahora + 2 h.
        if ($startsAt->lte($earliest)) {
            throw new BusinessRuleException('RN-48', 'Desde el portal se reserva con más de '.AvailabilityCalculator::PORTAL_NOTICE_HOURS.' horas de anticipación.');
        }
    }

    /**
     * RN-49, RF-149: dentro del plazo solo la clínica puede reprogramar o cancelar.
     *
     * @throws BusinessRuleException
     */
    public function ensureChangeable(Appointment $appointment): void
    {
        $hours = $this->settings->current()->portal_cancel_hours;

        if (now()->diffInMinutes($appointment->starts_at, false) < $hours * 60) {
            throw new BusinessRuleException(
                'RN-49',
                "Desde el portal puedes reprogramar o cancelar hasta {$hours} horas antes de la cita. Comunícate con la clínica.",
            );
        }
    }
}
