<?php

/*
 * Cambio de plan y funciones por plan (TASK-024; CUS-03, SDD §5.13; RN-08, RF-022, RF-023).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Route;

it('changes the plan of a clinic and audits it', function () {
    $tenant = Tenant::factory()->create(['subscription_plan' => 'basic']);
    $this->actingAsRole('super_admin');

    $this->putJson("/api/v1/platform/tenants/{$tenant->uuid}/plan", ['subscription_plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'enterprise');

    expect($tenant->fresh()->plan->code)->toBe('enterprise')
        ->and(AuditLog::query()->where('action', 'tenant.plan_changed')->count())->toBe(1);
})->group('RF-022', 'CUS-03');

it('rejects a plan whose dentist maximum is below the active dentists and says how many to deactivate', function () {
    $tenant = Tenant::factory()->create(['subscription_plan' => 'pro']);
    User::factory()->for($tenant)->count(5)->create(['role' => 'dentist']);
    User::factory()->for($tenant)->create(['role' => 'dentist', 'is_active' => false]);
    $this->actingAsRole('super_admin');

    $this->putJson("/api/v1/platform/tenants/{$tenant->uuid}/plan", ['subscription_plan' => 'basic'])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RF-022')
        ->assertJsonPath('dentists_to_deactivate', 3)
        ->assertJsonValidationErrors(['subscription_plan']);

    expect($tenant->fresh()->plan->code)->toBe('pro');
})->group('RN-08', 'RF-022');

it('answers 403 plan_feature_unavailable for a feature outside the plan', function (string $plan, int $status) {
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'plan.feature:risk'])
        ->get('/api/v1/_prueba/riesgo', fn () => response()->json(['data' => 'ok']));
    $tenant = Tenant::factory()->create(['subscription_plan' => $plan]);
    $this->actingAsRole('dentist', $tenant);

    $response = $this->getJson('/api/v1/_prueba/riesgo')->assertStatus($status);

    if ($status === 403) {
        $response->assertJsonPath('rule', 'plan_feature_unavailable');
    }
})->with([
    'basic sin predicción' => ['basic', 403],
    'pro con predicción' => ['pro', 200],
])->group('RN-08', 'RF-023');

it('keeps history readable after downgrading to a plan without the feature', function () {
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'plan.feature:ai'])
        ->post('/api/v1/_prueba/ia', fn () => response()->json(['data' => 'ok']));
    $tenant = Tenant::factory()->create(['subscription_plan' => 'pro']);
    $this->actingAsRole('super_admin');
    $this->putJson("/api/v1/platform/tenants/{$tenant->uuid}/plan", ['subscription_plan' => 'basic'])->assertOk();

    $this->actingAsRole('dentist', $tenant->fresh());
    $this->postJson('/api/v1/_prueba/ia')->assertForbidden()->assertJsonPath('rule', 'plan_feature_unavailable');
    $this->getJson('/api/v1/patients')->assertOk();
})->group('RF-023');
