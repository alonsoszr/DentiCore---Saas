<?php

/*
 * Base del portal (TASK-085; SDD §3.3, §4.2; RF-061, RN-03, RN-06, RN-12, RN-13, RN-80, DI-13):
 * sujetos que ve la cuenta y los middleware `portal.subject`, `portal.adult` y
 * `portal.self_booking`.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Support\PortalFixtures;
use Tests\TestCase;

/** Rutas de prueba con la pila PORT y cada middleware del portal. */
function registerPortalProbeRoutes(): void
{
    $stack = ['api', 'auth:sanctum', '2fa', 'tenant', 'role:patient'];

    Route::middleware([...$stack, 'portal.subject'])
        ->get('/api/v1/_probe/portal/patients/{patient}', fn (Patient $patient) => ['id' => $patient->uuid]);
    Route::middleware([...$stack, 'portal.subject', 'portal.adult'])
        ->post('/api/v1/_probe/portal/patients/{patient}/decision', fn (Patient $patient) => ['id' => $patient->uuid]);
    Route::middleware([...$stack, 'portal.self_booking'])
        ->get('/api/v1/_probe/portal/booking', fn () => ['ok' => true]);
}

function subjectIds(TestCase $test): array
{
    return collect($test->getJson('/api/v1/portal/subjects')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
}

it('lists the own record of the account holder', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $this->actingWithToken($user);

    $this->getJson('/api/v1/portal/subjects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $patient->uuid)
        ->assertJsonPath('data.0.first_name', $patient->first_name)
        ->assertJsonPath('data.0.is_minor', false)
        ->assertJsonPath('data.0.relationship', 'titular');
})->group('RF-061', 'DI-13');

it('lists the current represented minors next to the own record', function () {
    $tenant = Tenant::factory()->create();
    [$user, $own] = PortalFixtures::holder($tenant);
    $first = PortalFixtures::minor($tenant);
    $second = PortalFixtures::minor($tenant, '2018-07-01');
    PortalFixtures::representative($tenant, $first, $user);
    PortalFixtures::representative($tenant, $second, $user);
    $this->actingWithToken($user);

    expect(subjectIds($this))->toBe(collect([$own->uuid, $first->uuid, $second->uuid])->sort()->values()->all());
    $this->getJson('/api/v1/portal/subjects')->assertJsonFragment(['id' => $first->uuid, 'is_minor' => true, 'relationship' => 'representado']);
})->group('RF-061', 'RN-12');

it('hides blocked, merged and deceased records and ended or adult representations', function () {
    $tenant = Tenant::factory()->create();
    [$blocked] = PortalFixtures::holder($tenant, ['archive_status' => 'bloqueado'], withConsent: false);
    [$deceased] = PortalFixtures::holder($tenant, ['deceased_on' => '2026-01-10'], withConsent: false);
    $this->actingWithToken($blocked);
    expect(subjectIds($this))->toBe([]);
    $this->actingWithToken($deceased);
    expect(subjectIds($this))->toBe([]);

    // Representación terminada y representado que ya cumplió 18 años aunque la tarea diaria no
    // haya cerrado aún la representación (SDD §3.3: edad a la fecha de la operación).
    $representative = User::factory()->for($tenant)->create(['role' => 'patient']);
    $ended = PortalFixtures::minor($tenant);
    $adult = PortalFixtures::minor($tenant, '2000-05-01');
    PortalFixtures::representative($tenant, $ended, $representative);
    PortalFixtures::representative($tenant, $adult, $representative);
    TenantContext::run($tenant, fn () => PortalFixtures::representation($tenant, $ended)->forceFill([
        'valid_until' => '2026-01-01', 'ended_reason' => 'revocada',
    ])->save());
    $this->actingWithToken($representative);

    expect(subjectIds($this))->toBe([]);
})->group('RN-13', 'RN-80');

it('forbids the portal routes to the clinic staff', function (string $role) {
    $this->actingAsRole($role);

    $this->getJson('/api/v1/portal/subjects')->assertForbidden();
})->with(['clinic_admin', 'dentist', 'receptionist'])->group('RN-06');

it('returns 404 when the route patient is not a subject of the account', function () {
    registerPortalProbeRoutes();
    $tenant = Tenant::factory()->create();
    [$user, $own] = PortalFixtures::holder($tenant);
    [, $other] = PortalFixtures::holder($tenant);
    [, $elsewhere] = PortalFixtures::holder(Tenant::factory()->create());
    $this->actingWithToken($user);

    $this->getJson("/api/v1/_probe/portal/patients/{$own->uuid}")->assertOk()->assertJsonPath('id', $own->uuid);
    $this->getJson("/api/v1/_probe/portal/patients/{$other->uuid}")->assertNotFound();
    $this->getJson("/api/v1/_probe/portal/patients/{$elsewhere->uuid}")->assertNotFound();
})->group('RN-03', 'CA-37.5');

it('lets only an adult holder or the current representative decide', function () {
    registerPortalProbeRoutes();
    $tenant = Tenant::factory()->create();
    [$adultUser, $adult] = PortalFixtures::holder($tenant);
    [$minorUser, $minor] = PortalFixtures::holder($tenant, ['birth_date' => '2012-04-20'], withConsent: false);
    $representative = PortalFixtures::representative($tenant, $minor);

    $this->actingWithToken($adultUser)->postJson("/api/v1/_probe/portal/patients/{$adult->uuid}/decision")->assertOk();
    $this->actingWithToken($minorUser)->postJson("/api/v1/_probe/portal/patients/{$minor->uuid}/decision")
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-12')
        ->assertJsonPath('detail', 'La decisión corresponde al representante legal.');
    $this->actingWithToken($representative)->postJson("/api/v1/_probe/portal/patients/{$minor->uuid}/decision")->assertOk();
})->group('RN-12');

it('forbids self booking when the clinic has not enabled it', function () {
    registerPortalProbeRoutes();
    $tenant = Tenant::factory()->create();
    [$user] = PortalFixtures::holder($tenant);
    $this->actingWithToken($user);

    $this->getJson('/api/v1/_probe/portal/booking')->assertForbidden();

    TenantContext::run($tenant, fn () => ClinicSetting::query()->sole()->forceFill(['self_booking_enabled' => true])->save());
    $this->getJson('/api/v1/_probe/portal/booking')->assertOk();
})->group('RF-148', 'RF-026');
