<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Datos sintéticos de riesgo de caries (SDD §2.14.2; RES-08): paciente con la finalidad (d) y
 * secciones válidas de variables.
 */
final class RiskFixtures
{
    /**
     * Paciente con consentimiento vigente; `$risk` indica la finalidad (d) «predicción».
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function patient(Tenant $tenant, bool $risk = true, array $attributes = []): Patient
    {
        return TenantContext::run($tenant, function () use ($tenant, $risk, $attributes) {
            $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', ...$attributes]);
            $grantor = $patient->document_number;

            // Un menor consiente por medio de su representante legal vigente (RN-12).
            if ($patient->isMinorOn()) {
                $grantor = '4'.fake()->unique()->numerify('#######');
                app(LegalRepresentativeService::class)->add($patient, [
                    'document_type' => 'dni', 'document_number' => $grantor, 'first_name' => 'Rosa', 'last_name' => 'Mamani',
                    'relationship' => 'madre', 'phone' => '912345678', 'email' => null, 'valid_from' => '2020-01-01',
                ]);
            }

            app(ConsentService::class)->grant(
                $patient,
                ['channel' => 'presencial', 'confirmation_document_number' => $grantor, 'purpose_risk' => $risk],
                User::factory()->for($tenant)->create(['role' => 'receptionist']),
                '127.0.0.1',
            );

            return $patient;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function clinical(array $overrides = []): array
    {
        return ['cpod' => 3, 'ceod' => null, 'plaque_index_pct' => 42.5, 'active_lesions' => 2, ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    public static function behavioral(array $overrides = []): array
    {
        return [
            'sugar_between_meals' => '3', 'fluoride_toothpaste' => true, 'brushing_per_day' => '2', 'dental_visits_per_year' => '1',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sociodemographic(array $overrides = []): array
    {
        return ['respondent' => 'titular', 'education_level' => 'secundaria', 'employment_status' => 'informal', ...$overrides];
    }
}
