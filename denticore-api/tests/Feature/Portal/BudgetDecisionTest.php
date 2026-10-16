<?php

/*
 * Presupuestos en el portal (TASK-086; CUS-36, CUS-37; RF-121, RF-122, RF-123, RN-03, RN-12,
 * RN-36, CA-37.5): el paciente o su representante consulta sus presupuestos y decide sobre el
 * emitido con su sesión.
 */

use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PortalFixtures;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/** @param  array<string, mixed>  $payload */
function portalDecide(string $budget, array $payload = ['decision' => 'aceptado']): TestResponse
{
    return test()->postJson("/api/v1/portal/budgets/{$budget}/decision", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('returns 404 when a patient decides on another patient budget', function () {
    $tenant = Tenant::factory()->create();
    [$user] = PortalFixtures::holder($tenant);
    [, $other] = PortalFixtures::holder($tenant);
    $budget = PortalFixtures::issuedBudget($tenant, $other);
    $this->actingWithToken($user);

    portalDecide($budget)->assertNotFound();
    $this->getJson("/api/v1/portal/budgets/{$budget}")->assertNotFound();
    $this->getJson("/api/v1/portal/budgets/{$budget}/pdf")->assertNotFound();
    $this->getJson("/api/v1/portal/patients/{$other->uuid}/budgets")->assertNotFound();
})->group('T-093', 'CA-37.5', 'RN-03');

it('lists and shows the issued budgets of the patient but not the drafts', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $budget = PortalFixtures::issuedBudget($tenant, $patient);
    $draft = $this->postJson("/api/v1/treatment-plans/{$this->getJson("/api/v1/budgets/{$budget}")->json('data.plan_id')}/budgets", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->json('data.id');
    $this->actingWithToken($user);

    $this->getJson("/api/v1/portal/patients/{$patient->uuid}/budgets")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $budget)
        ->assertJsonPath('data.0.status', 'emitido')
        ->assertJsonPath('data.0.total', '150.00');
    $this->getJson("/api/v1/portal/budgets/{$budget}")->assertOk()->assertJsonPath('data.lines.0.unit_price', '150.00');
    $this->getJson("/api/v1/portal/budgets/{$budget}/pdf")->assertOk()->assertJsonStructure(['data' => ['status', 'url']]);
    $this->getJson("/api/v1/portal/budgets/{$draft}")->assertNotFound();
})->group('CUS-36', 'RF-121');

it('accepts the budget from the portal with the session as the signer', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $budget = PortalFixtures::issuedBudget($tenant, $patient);
    $this->actingWithToken($user);

    portalDecide($budget, ['decision' => 'aceptado', 'signer' => 'representante', 'signer_document_number' => '1'])
        ->assertOk()
        ->assertJsonPath('data.status', 'aceptado')
        ->assertJsonPath('data.decision.channel', 'portal')
        ->assertJsonPath('data.decision.signer', 'titular')
        ->assertJsonPath('data.decision.by.id', $user->uuid);

    portalDecide($budget)->assertStatus(409)->assertJsonPath('rule', 'RN-36');
})->group('CUS-37', 'RN-36', 'CA-37.1');

it('rejects the budget with an optional reason', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $budget = PortalFixtures::issuedBudget($tenant, $patient);
    $this->actingWithToken($user);

    portalDecide($budget, ['decision' => 'rechazado', 'rejection_reason' => 'nada'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');
    portalDecide($budget, ['decision' => 'rechazado', 'rejection_reason' => 'precio', 'rejection_detail' => 'Lo pensaré'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rechazado')
        ->assertJsonPath('data.decision.rejection_reason', 'precio')
        ->assertJsonPath('data.decision.rejection_detail', 'Lo pensaré');
})->group('CUS-37', 'RF-123');

it('lets the representative decide for the minor and forbids the minor holder', function () {
    $tenant = Tenant::factory()->create();
    [$minorUser, $minor] = PortalFixtures::holder($tenant, ['birth_date' => '2012-04-20'], withConsent: false);
    $representative = PortalFixtures::representative($tenant, $minor);
    $budget = PortalFixtures::issuedBudget($tenant, $minor);

    $this->actingWithToken($minorUser);
    portalDecide($budget)->assertForbidden()->assertJsonPath('rule', 'RN-12');
    $this->getJson("/api/v1/portal/budgets/{$budget}")->assertOk();

    $this->actingWithToken($representative);
    portalDecide($budget)
        ->assertOk()
        ->assertJsonPath('data.decision.channel', 'portal')
        ->assertJsonPath('data.decision.signer', 'representante');
})->group('CUS-37', 'RN-12');
