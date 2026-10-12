<?php

/*
 * Visibilidad por rol del riesgo de caries (TASK-084; SDD §3.5; CUS-56, RF-179, RNF-044): la
 * respuesta de cada rol no contiene las claves que §3.5 le prohíbe, en ningún nivel del JSON.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\CircuitBreaker;
use Illuminate\Support\Str;
use Tests\Support\MlEngine;
use Tests\Support\RiskFixtures;

/** Claves de un JSON en cualquier nivel. */
function jsonKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    return collect($value)->flatMap(fn ($child, $key) => [...(is_string($key) ? [$key] : []), ...jsonKeys($child)])->unique()->values()->all();
}

beforeEach(fn () => app(CircuitBreaker::class)->reset());
afterEach(fn () => app(CircuitBreaker::class)->reset());

it('never sends the probability or the individual explanation to the clinic administrator', function () {
    ModelVersion::factory()->active()->create(['version' => '1.0.0']);
    MlEngine::predicts(0.72);
    $tenant = Tenant::factory()->plan('pro')->create();
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $patient = RiskFixtures::patient($tenant);
    $set = RiskVariableSet::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'clinical_captured_by' => $dentist->id]);
    $created = $this->actingWithToken($dentist)
        ->postJson("/api/v1/patients/{$patient->uuid}/risk-predictions", ['risk_variable_set_id' => $set->uuid], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated();
    $forbidden = ['probability', 'top_increasing', 'top_decreasing', 'explanation_individual', 'shap'];

    // El odontólogo recibe la explicación individual.
    expect(jsonKeys($created->json()))->toContain('probability', 'top_increasing', 'explanation_individual');

    // El administrador: nivel, confianza, explicación global y leyendas (CUS-56).
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $shown = $this->actingWithToken($admin)->getJson('/api/v1/risk-predictions/'.$created->json('data.id'))->assertOk();
    expect(jsonKeys($shown->json()))->not->toContain(...$forbidden)
        ->and(jsonKeys($shown->json()))->toContain('level', 'confidence', 'explanation_global', 'legend');

    $record = $this->actingWithToken($admin)->getJson("/api/v1/patients/{$patient->uuid}/clinical-record")->assertOk();
    expect(jsonKeys($record->json('data.current_risk_prediction')))->not->toContain(...$forbidden);
})->group('RF-179', 'RNF-044', 'CUS-56', 'TASK-084');

it('hides predictions from reception and risk variables from the clinic administrator', function () {
    $tenant = Tenant::factory()->plan('pro')->create();
    $patient = RiskFixtures::patient($tenant);
    RiskVariableSet::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id]);

    $this->actingAsRole('receptionist', $tenant);
    $this->getJson("/api/v1/patients/{$patient->uuid}/clinical-record")->assertOk()->assertJsonPath('data.current_risk_prediction', null);

    $this->actingAsRole('clinic_admin', $tenant);
    $this->getJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets")->assertForbidden();
})->group('RF-162', 'CUS-54', 'CUS-56', 'TASK-084');
