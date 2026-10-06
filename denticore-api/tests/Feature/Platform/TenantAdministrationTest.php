<?php

/*
 * Listado, detalle, edición e invitación de clínicas (TASK-023; CUS-01, CUS-02; RF-010,
 * RF-013, RF-014, RF-016, RF-018, RF-022).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\SubscriptionPlan;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tokens\OneTimeToken;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Tests\Support\Outbox;

it('lists clinics with plan, status and active dentists, searching and filtering', function () {
    $sonrisa = Tenant::factory()->plan('pro')->create(['name' => 'Clínica Sonrisa', 'slug' => 'sonrisa', 'ruc' => '20600000013']);
    $muela = Tenant::factory()->plan('basic')->create(['name' => 'Centro Muela Sana', 'slug' => 'muela', 'status' => 'suspendida']);
    User::factory()->for($sonrisa)->count(2)->create(['role' => 'dentist']);
    User::factory()->for($sonrisa)->create(['role' => 'dentist', 'status' => 'inactivo']);
    $this->actingAsRole('super_admin');

    $this->getJson('/api/v1/platform/tenants')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'plan' => ['code', 'name'], 'status', 'active_dentists', 'created_at']], 'meta' => ['total']]);

    $byName = $this->getJson('/api/v1/platform/tenants?q=sonrisa')->assertOk();
    expect($byName->json('data.0'))->toMatchArray(['id' => $sonrisa->uuid, 'active_dentists' => 2]);
    expect($byName->json('data'))->toHaveCount(1);

    expect($this->getJson('/api/v1/platform/tenants?q=20600000013')->json('data.*.id'))->toBe([$sonrisa->uuid])
        ->and($this->getJson('/api/v1/platform/tenants?status=suspendida')->json('data.*.id'))->toBe([$muela->uuid])
        ->and($this->getJson('/api/v1/platform/tenants?plan=pro')->json('data.*.id'))->toBe([$sonrisa->uuid]);

    $this->getJson('/api/v1/platform/tenants?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
})->group('RF-018', 'RF-010');

it('shows a clinic with its pending admin', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'password' => null]);
    $this->actingAsRole('super_admin');

    $this->getJson("/api/v1/platform/tenants/{$tenant->uuid}")
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->uuid)
        ->assertJsonPath('data.admin.id', $admin->uuid)
        ->assertJsonPath('data.admin.status', 'pendiente_activacion');

    $this->getJson('/api/v1/platform/tenants/'.fake()->uuid())->assertNotFound();
})->group('RF-018');

it('updates the clinic data but never its access code', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-fija']);
    $this->actingAsRole('super_admin');

    $this->patchJson("/api/v1/platform/tenants/{$tenant->uuid}", [
        'name' => 'Sonrisa Norte',
        'ruc' => '20600000013',
        'slug' => 'otro-codigo',
    ])->assertOk()->assertJsonPath('data.name', 'Sonrisa Norte')->assertJsonPath('data.slug', 'clinica-fija');

    expect($tenant->fresh())->name->toBe('Sonrisa Norte')->ruc->toBe('20600000013')->slug->toBe('clinica-fija');

    $this->patchJson("/api/v1/platform/tenants/{$tenant->uuid}", ['ruc' => '20600000014'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ruc']);
})->group('RF-013', 'RF-014', 'DD-29');

it('resends the invitation invalidating the previous link', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'password' => null]);
    $tokens = app(OneTimeTokenService::class);
    ['token' => $previous] = $tokens->issue($admin, TokenPurpose::Invitation, now()->addHours(72));
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$tenant->uuid}/admin-invitation")->assertNoContent();

    expect($tokens->find($previous, TokenPurpose::Invitation))->toBeNull()
        ->and(OneTimeToken::query()->whereNull('invalidated_at')->whereNull('used_at')->count())->toBe(1);
    Outbox::assertRecorded('notification.send', times: 1);
})->group('RF-016', 'CA-01.5');

it('rejects resending when the admin is already active', function () {
    $tenant = Tenant::factory()->withAdmin()->create();
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$tenant->uuid}/admin-invitation")
        ->assertStatus(409)
        ->assertJsonPath('rule', 'RF-016');
    Outbox::assertNotRecorded('notification.send');
})->group('RF-016');

it('lists the subscription plans', function () {
    $this->actingAsRole('super_admin');

    $this->getJson('/api/v1/platform/plans')
        ->assertOk()
        ->assertJsonPath('data.*.code', ['basic', 'pro', 'enterprise'])
        ->assertJsonPath('data.0.max_dentists', 2)
        ->assertJsonPath('data.2.max_dentists', null)
        ->assertJsonPath('data.1.id', SubscriptionPlan::forCode('pro')->uuid);
})->group('RF-022', 'DD-16');

it('forbids the platform routes to clinic roles', function (string $method, string $uri) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $this->json($method, str_replace('{tenant}', $tenant->uuid, $uri))->assertForbidden();
})->with([
    ['GET', '/api/v1/platform/tenants'],
    ['GET', '/api/v1/platform/tenants/{tenant}'],
    ['PATCH', '/api/v1/platform/tenants/{tenant}'],
    ['POST', '/api/v1/platform/tenants/{tenant}/admin-invitation'],
    ['GET', '/api/v1/platform/plans'],
])->group('RN-04', 'RF-004');
