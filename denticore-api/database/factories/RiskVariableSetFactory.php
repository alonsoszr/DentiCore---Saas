<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskVariableSet;

/**
 * Conjunto completo de variables de un paciente adulto sintético (SDD §2.14.2; RES-08), capturado
 * hoy por un odontólogo y una recepcionista de la misma clínica.
 *
 * @extends TenantScopedFactory<RiskVariableSet>
 */
class RiskVariableSetFactory extends TenantScopedFactory
{
    /**
     * @var class-string<RiskVariableSet>
     */
    protected $model = RiskVariableSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'age_group' => 'adulto',
            'age_years' => 34,
            'clinical' => ['cpod' => 3, 'ceod' => null, 'plaque_index_pct' => 42.5, 'active_lesions' => 2],
            'behavioral' => [
                'sugar_between_meals' => '3', 'fluoride_toothpaste' => true, 'brushing_per_day' => '2', 'dental_visits_per_year' => '1',
            ],
            'sociodemographic' => [
                'respondent' => 'titular', 'education_level' => 'secundaria', 'employment_status' => 'informal', 'family_structure' => null,
            ],
            'is_complete' => true,
            'clinical_captured_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'dentist'])->id,
            'clinical_captured_at' => now(),
            'sociodemographic_captured_by' => fn (array $attributes) => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'receptionist'])->id,
            'sociodemographic_captured_at' => now(),
        ];
    }
}
