<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\TreatmentPlan;

/**
 * Plan en borrador de un paciente y un odontólogo sintéticos de la misma clínica (RES-08).
 *
 * @extends TenantScopedFactory<TreatmentPlan>
 */
class TreatmentPlanFactory extends TenantScopedFactory
{
    /**
     * @var class-string<TreatmentPlan>
     */
    protected $model = TreatmentPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => 'Plan de prueba',
            'status' => 'borrador',
            'origin' => 'manual',
            'created_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
        ];
    }
}
