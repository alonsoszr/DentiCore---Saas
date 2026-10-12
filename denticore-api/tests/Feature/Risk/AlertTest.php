<?php

/*
 * Reconocimiento de alertas de riesgo alto (TASK-082; SDD §5.8.4; CUS-58; RN-65, RF-171; T-135).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/** @return array{tenant: Tenant, dentist: User, alert: RiskAlert} */
function alertScenario(): array
{
    $alert = RiskAlert::factory()->create();
    $tenant = Tenant::query()->findOrFail($alert->tenant_id);
    $dentist = User::query()->findOrFail($alert->dentist_id);
    test()->actingWithToken($dentist);

    return compact('tenant', 'dentist', 'alert');
}

/** @param array<string, mixed> $body */
function acknowledge(RiskAlert $alert, array $body): TestResponse
{
    return test()->postJson("/api/v1/risk-alerts/{$alert->uuid}/acknowledge", $body);
}

it('rejects acknowledging an alert without a registered action', function () {
    ['tenant' => $tenant, 'alert' => $alert] = alertScenario();

    acknowledge($alert, [])->assertUnprocessable()->assertJsonValidationErrors(['action']);
    acknowledge($alert, ['action' => 'justificacion', 'justification' => 'Muy corta'])->assertUnprocessable()->assertJsonValidationErrors(['justification']);
    acknowledge($alert, ['action' => 'plan_preventivo'])->assertUnprocessable()->assertJsonValidationErrors(['treatment_plan_id']);

    expect(TenantContext::run($tenant, fn () => $alert->fresh()->status))->toBe('abierta');
})->group('T-135', 'RN-65', 'RF-171');

it('acknowledges with a justification once and audits the action', function () {
    ['tenant' => $tenant, 'alert' => $alert] = alertScenario();
    $justification = 'Paciente con control preventivo vigente en otra sede.';

    acknowledge($alert, ['action' => 'justificacion', 'justification' => $justification])->assertOk()
        ->assertJsonPath('data.status', 'reconocida')
        ->assertJsonPath('data.action', 'justificacion')
        ->assertJsonPath('data.justification', $justification);
    acknowledge($alert, ['action' => 'justificacion', 'justification' => $justification])->assertConflict()->assertJsonPath('rule', 'RN-65');

    expect(TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'risk.alert_acknowledged')->sole()->metadata))
        ->toMatchArray(['action' => 'justificacion']);
})->group('RN-65', 'RF-171');

it('links a preventive plan or a control appointment of the same patient', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'alert' => $alert] = alertScenario();
    $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $alert->patient_id, 'created_by' => $dentist->id]);
    $otherPlan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $dentist->id]);

    acknowledge($alert, ['action' => 'plan_preventivo', 'treatment_plan_id' => $otherPlan->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors(['treatment_plan_id']);
    acknowledge($alert, ['action' => 'plan_preventivo', 'treatment_plan_id' => $plan->uuid])->assertOk()
        ->assertJsonPath('data.treatment_plan_id', $plan->uuid);

    TenantContext::run($tenant, fn () => expect($plan->fresh()->only(['origin', 'risk_alert_id']))->toBe(['origin' => 'alerta', 'risk_alert_id' => $alert->id]));

    $second = RiskAlert::factory()->create(['tenant_id' => $tenant->id]);
    $appointment = Appointment::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $second->patient_id]);
    test()->actingWithToken(User::query()->findOrFail($second->dentist_id));
    acknowledge($second, ['action' => 'cita_control', 'appointment_id' => $appointment->uuid])->assertOk()
        ->assertJsonPath('data.appointment_id', $appointment->uuid);

    expect(TenantContext::run($tenant, fn () => $appointment->fresh()->risk_alert_id))->toBe($second->id);
})->group('RN-65', 'RF-171');

it('answers 404 to another dentist and lists only the alerts of the authenticated dentist', function () {
    ['tenant' => $tenant, 'alert' => $alert] = alertScenario();
    RiskAlert::factory()->create(['tenant_id' => $tenant->id]);

    test()->getJson('/api/v1/risk-alerts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alert->uuid)
        ->assertJsonPath('data.0.prediction.level', 'alto');

    test()->actingAsRole('dentist', $tenant);
    acknowledge($alert, ['action' => 'justificacion', 'justification' => 'Paciente con control preventivo vigente en otra sede.'])->assertNotFound();
    test()->getJson('/api/v1/risk-alerts')->assertOk()->assertJsonCount(0, 'data');
})->group('CUS-58', 'RN-65');
