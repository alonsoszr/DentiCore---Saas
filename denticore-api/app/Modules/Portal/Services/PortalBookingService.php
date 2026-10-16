<?php

namespace App\Modules\Portal\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Support\Http\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reserva desde el portal (CUS-47 FA-1; RF-148, RN-48): con la duración del tipo y el origen
 * `portal`. Bloquear al paciente serializa sus reservas simultáneas y mantiene el límite de 2
 * citas activas autoagendadas.
 */
class PortalBookingService
{
    public function __construct(private AppointmentService $appointments, private PortalAppointmentRules $rules) {}

    /**
     * @throws BusinessRuleException
     */
    public function book(Patient $patient, User $dentist, AppointmentType $type, CarbonImmutable $startsAt, User $actor): Appointment
    {
        return DB::transaction(function () use ($patient, $dentist, $type, $startsAt, $actor): Appointment {
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
    }
}
