<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Services\AttentionService;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Support\Facades\DB;

/**
 * Check-in de la cita (SDD §5.7; CUS-50, CUS-25; RF-151, RF-067, RN-10, RN-50): el día de la
 * cita, desde 60 minutos antes de su inicio hasta su fin. Abre la atención del odontólogo de la
 * cita aunque falte el consentimiento (con el aviso en la respuesta) y la cita pasa a `en_atencion`.
 */
class CheckInService
{
    public const EARLY_MINUTES = 60;

    public function __construct(private AttentionService $attentions, private AuditLogger $audit) {}

    /**
     * @return array{appointment: Appointment, attention: Attention, consent_warning: array{rule: string, detail: string}|null}
     *
     * @throws BusinessRuleException
     */
    public function checkIn(Appointment $appointment): array
    {
        return DB::transaction(function () use ($appointment): array {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->with(['patient', 'dentist'])->firstOrFail();

            if (! in_array($appointment->status, ['programada', 'confirmada'], true)) {
                throw new BusinessRuleException('RF-151', "No se puede registrar la llegada de una cita en estado {$appointment->status}.", status: 409);
            }

            $clock = ClinicClock::for(TenantContext::tenantOrFail());
            $now = $clock->now();
            $insideWindow = $now >= $appointment->starts_at->copy()->subMinutes(self::EARLY_MINUTES)
                && $now <= $appointment->ends_at
                && $clock->localDate($now) === $clock->localDate($appointment->starts_at);

            if (! $insideWindow) {
                throw new BusinessRuleException('RN-50', 'La llegada se registra el día de la cita, desde 60 minutos antes de su inicio hasta su fin.');
            }

            ['attention' => $attention, 'consent_warning' => $warning] = $this->attentions->open($appointment->patient, $appointment->dentist, $appointment);

            $appointment->forceFill([
                'status' => 'en_atencion',
                'checked_in_at' => now(),
                'reconfirmation_pending' => false,
            ])->save();
            $this->audit->record(AuditEvent::AppointmentCheckedIn, $appointment, ['status']);

            return ['appointment' => $appointment, 'attention' => $attention, 'consent_warning' => $warning];
        });
    }
}
