<?php

/*
 * Variables de riesgo (TASK-079; SDD §2.14.2, §5.8.1; CUS-54; RF-161, RF-162, RF-164, RN-58,
 * RN-10, DI-20; T-138).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RiskFixtures;

/**
 * @param  array<string, mixed>  $sections
 */
function captureRisk(Patient $patient, array $sections): TestResponse
{
    return test()->postJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets", $sections, ['Idempotency-Key' => (string) Str::uuid()]);
}

/** @return array{tenant: Tenant, patient: Patient} */
function riskScenario(string $role = 'dentist', string $plan = 'pro', bool $risk = true, array $patient = []): array
{
    $tenant = Tenant::factory()->plan($plan)->create();
    test()->actingAsRole($role, $tenant);

    return ['tenant' => $tenant, 'patient' => RiskFixtures::patient($tenant, $risk, $patient)];
}

function latestRiskSet(Tenant $tenant): RiskVariableSet
{
    return TenantContext::run($tenant, fn () => RiskVariableSet::query()->latest('id')->firstOrFail());
}

it('lets reception write only sociodemographic fields and dentists only clinical ones', function () {
    ['tenant' => $tenant, 'patient' => $patient] = riskScenario('receptionist');

    captureRisk($patient, ['clinical' => RiskFixtures::clinical()])->assertForbidden();
    captureRisk($patient, ['sociodemographic' => RiskFixtures::sociodemographic()])->assertCreated()
        ->assertJsonPath('data.is_complete', false)
        ->assertJsonPath('data.sociodemographic.education_level', 'secundaria');

    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    test()->actingWithToken($dentist);
    captureRisk($patient, ['sociodemographic' => RiskFixtures::sociodemographic()])->assertForbidden();
    captureRisk($patient, ['clinical' => RiskFixtures::clinical(), 'behavioral' => RiskFixtures::behavioral()])->assertOk()
        ->assertJsonPath('data.is_complete', true)
        ->assertJsonPath('data.missing', []);

    $set = latestRiskSet($tenant);
    expect(TenantContext::run($tenant, fn () => RiskVariableSet::query()->count()))->toBe(1)
        ->and($set->clinical_captured_by)->toBe($dentist->id)
        ->and($set->age_group)->toBe('adulto')
        ->and($set->sociodemographic_captured_at)->not->toBeNull();
})->group('T-138', 'RF-162', 'RN-58', 'CUS-54');

it('rejects out of domain values with an error on the field', function () {
    ['patient' => $patient] = riskScenario();

    captureRisk($patient, [
        'clinical' => RiskFixtures::clinical(['cpod' => 33, 'plaque_index_pct' => 42.55]),
        'behavioral' => RiskFixtures::behavioral(['brushing_per_day' => '4']),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['clinical.cpod', 'clinical.plaque_index_pct', 'behavioral.brushing_per_day']);
})->group('RF-161', 'RNF-174');

it('requires the caries index of the current dentition and the family structure only for minors', function () {
    ['tenant' => $tenant, 'patient' => $child] = riskScenario(patient: ['birth_date' => now()->subYears(4)->toDateString()]);

    // Dentición temporal (DI-20): ceod obligatorio, CPOD no.
    captureRisk($child, ['clinical' => RiskFixtures::clinical(['cpod' => null, 'ceod' => null])])
        ->assertUnprocessable()->assertJsonValidationErrors(['clinical.ceod'])->assertJsonMissingValidationErrors(['clinical.cpod']);
    captureRisk($child, ['clinical' => RiskFixtures::clinical(['cpod' => null, 'ceod' => 4]), 'behavioral' => RiskFixtures::behavioral()])
        ->assertCreated()->assertJsonPath('data.age_group', 'menor')->assertJsonPath('data.missing', ['respondent', 'education_level', 'employment_status', 'family_structure']);

    test()->actingAsRole('receptionist', $tenant);
    captureRisk($child, ['sociodemographic' => RiskFixtures::sociodemographic()])
        ->assertUnprocessable()->assertJsonValidationErrors(['sociodemographic.respondent', 'sociodemographic.family_structure']);
    captureRisk($child, ['sociodemographic' => RiskFixtures::sociodemographic(['respondent' => 'representante', 'family_structure' => 'monoparental'])])
        ->assertOk()->assertJsonPath('data.is_complete', true);

    // Un adulto no lleva estructura familiar.
    $adult = RiskFixtures::patient($tenant);
    captureRisk($adult, ['sociodemographic' => RiskFixtures::sociodemographic(['family_structure' => 'biparental'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['sociodemographic.family_structure']);
})->group('DI-20', 'RF-161', 'RN-12');

it('creates a new set that supersedes a locked one and copies the sections not edited', function () {
    ['tenant' => $tenant, 'patient' => $patient] = riskScenario();
    captureRisk($patient, ['clinical' => RiskFixtures::clinical(), 'behavioral' => RiskFixtures::behavioral()])->assertCreated();
    $locked = latestRiskSet($tenant);
    TenantContext::run($tenant, fn () => $locked->forceFill(['locked_at' => now()])->save());

    $response = test()->patchJson("/api/v1/risk-variable-sets/{$locked->uuid}", ['clinical' => RiskFixtures::clinical(['active_lesions' => 5])])
        ->assertCreated()
        ->assertJsonPath('data.supersedes_id', $locked->uuid)
        ->assertJsonPath('data.clinical.active_lesions', 5)
        ->assertJsonPath('data.behavioral.sugar_between_meals', '3');

    expect($response->json('data.id'))->not->toBe($locked->uuid)
        ->and(TenantContext::run($tenant, fn () => $locked->fresh()->clinical['active_lesions']))->toBe(2);

    // El conjunto reemplazado ya no se edita: 409 con el más reciente.
    test()->patchJson("/api/v1/risk-variable-sets/{$locked->uuid}", ['clinical' => RiskFixtures::clinical()])
        ->assertConflict()->assertJsonPath('rule', 'RF-164');
})->group('RF-164', 'RN-60');

it('requires the prediction purpose, the risk plan and audits the capture without values', function () {
    ['patient' => $withoutRisk] = riskScenario(risk: false);
    captureRisk($withoutRisk, ['clinical' => RiskFixtures::clinical()])->assertUnprocessable()->assertJsonPath('rule', 'RN-58');

    ['patient' => $basic] = riskScenario(plan: 'basic', risk: false);
    captureRisk($basic, ['clinical' => RiskFixtures::clinical()])->assertForbidden();

    ['tenant' => $tenant, 'patient' => $patient] = riskScenario();
    captureRisk($patient, ['clinical' => RiskFixtures::clinical()])->assertCreated();
    $log = TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'risk.variables_captured')->sole());
    expect($log->changed_fields)->toBe(['clinical'])
        ->and(json_encode($log->metadata))->not->toContain('42.5');
})->group('RN-58', 'RN-08', 'RF-186');

it('lists the versions of the variable sets of a patient for dentists and reception', function () {
    ['tenant' => $tenant, 'patient' => $patient] = riskScenario();
    captureRisk($patient, ['clinical' => RiskFixtures::clinical()])->assertCreated();

    test()->getJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets")->assertOk()->assertJsonCount(1, 'data');
    test()->actingAsRole('clinic_admin', $tenant);
    test()->getJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets")->assertForbidden();
})->group('RF-164', 'CUS-54');
