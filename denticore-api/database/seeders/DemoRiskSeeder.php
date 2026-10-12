<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Riesgo de caries de demostración de la «Clínica Demo» (solo entorno local; datos sintéticos,
 * RES-08): una paciente adulta con consentimiento vigente que incluye la finalidad (d)
 * «predicción» y un registro de variables completo, lista para calcular su riesgo. La E2E con el
 * motor detenido la usa porque nunca se le calculó una predicción. Es idempotente:
 * `php artisan db:seed --class=DemoRiskSeeder`.
 */
class DemoRiskSeeder extends Seeder
{
    public const DOCUMENT = '70000055';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Los datos de demostración solo se cargan en entorno local.');
        }

        $clinic = Tenant::query()->where('slug', 'clinica-demo')->first();
        $dentist = User::query()->where('email', 'dentista@clinica-demo.test')->first();
        $reception = User::query()->where('email', 'recepcion@clinica-demo.test')->first();

        if ($clinic === null || $dentist === null || $reception === null) {
            return;
        }

        TenantContext::run($clinic, function () use ($clinic, $dentist, $reception): void {
            if (Patient::query()->where('first_name', 'Rita')->where('last_name', 'Riesgo Demo')->exists()) {
                return;
            }

            $patient = Patient::factory()->for($clinic)->create([
                'document_type' => 'dni',
                'document_number' => self::DOCUMENT,
                'first_name' => 'Rita',
                'last_name' => 'Riesgo Demo',
                'birth_date' => '1988-04-12',
                'email' => null,
            ]);
            app(ConsentService::class)->grant(
                $patient,
                ['channel' => 'presencial', 'confirmation_document_number' => self::DOCUMENT, 'purpose_risk' => true],
                $reception,
                '127.0.0.1',
            );

            (new RiskVariableSet)->forceFill([
                'patient_id' => $patient->id,
                'age_group' => 'adulto',
                'age_years' => $patient->ageYears(),
                'clinical' => ['cpod' => 6, 'ceod' => null, 'plaque_index_pct' => 55.0, 'active_lesions' => 3],
                'behavioral' => [
                    'sugar_between_meals' => '3', 'fluoride_toothpaste' => true, 'brushing_per_day' => '1', 'dental_visits_per_year' => '<1',
                ],
                'sociodemographic' => [
                    'respondent' => 'titular', 'education_level' => 'secundaria', 'employment_status' => 'informal', 'family_structure' => null,
                ],
                'is_complete' => true,
                'clinical_captured_by' => $dentist->id,
                'clinical_captured_at' => now(),
                'sociodemographic_captured_by' => $reception->id,
                'sociodemographic_captured_at' => now(),
            ])->save();
        });
    }
}
