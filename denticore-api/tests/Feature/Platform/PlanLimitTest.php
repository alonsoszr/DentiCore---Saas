<?php

/*
 * T-021 (SDD §6.3; RN-08, RF-023): al bajar a un plan sin la función, la predicción de riesgo se
 * bloquea y el historial sigue legible. Las rutas de IA se agregan a esta prueba en TASK-129.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use Illuminate\Support\Str;
use Tests\Support\RiskFixtures;

it('forbids AI and predictions after downgrading to basic while keeping history readable', function () {
    $tenant = Tenant::factory()->plan('pro')->create();
    $patient = RiskFixtures::patient($tenant);
    // Un solo odontólogo: el plan basic admite 2 (RF-022).
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $set = RiskVariableSet::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'clinical_captured_by' => $dentist->id,
    ]);
    $prediction = RiskPrediction::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'risk_variable_set_id' => $set->id, 'requested_by' => $dentist->id,
    ]);

    $this->actingAsRole('super_admin');
    $this->putJson("/api/v1/platform/tenants/{$tenant->uuid}/plan", ['subscription_plan' => 'basic'])->assertOk();

    $this->actingWithToken($dentist);
    $this->postJson("/api/v1/patients/{$patient->uuid}/risk-predictions", ['risk_variable_set_id' => $set->uuid], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden()
        ->assertJsonPath('rule', 'plan_feature_unavailable');
    $this->postJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets", ['clinical' => RiskFixtures::clinical()])
        ->assertForbidden();

    $this->getJson("/api/v1/risk-predictions/{$prediction->uuid}")->assertOk()->assertJsonPath('data.level', 'bajo');
    $this->getJson("/api/v1/patients/{$patient->uuid}/risk-variable-sets")->assertOk()->assertJsonCount(1, 'data');
})->group('T-021', 'RN-08', 'RF-023');
