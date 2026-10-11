<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use Illuminate\Support\Carbon;

/**
 * Cita programada de 30 minutos, mañana a las 09:00 UTC, de un paciente, un odontólogo y un tipo
 * sintéticos de la misma clínica (RES-08).
 *
 * @extends TenantScopedFactory<Appointment>
 */
class AppointmentFactory extends TenantScopedFactory
{
    /**
     * @var class-string<Appointment>
     */
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'dentist_id' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
            'appointment_type_id' => fn (array $attributes) => AppointmentType::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'starts_at' => Carbon::tomorrow()->setTime(9, 0),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addMinutes(30),
            'status' => 'programada',
            'origin' => 'clinica',
            'created_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'receptionist'])->id,
        ];
    }
}
