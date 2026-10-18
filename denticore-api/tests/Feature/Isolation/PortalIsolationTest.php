<?php

/*
 * Aislamiento de las rutas del portal (TASK-090; SDD §3.3, §6.3; RN-03, RF-003, RF-004, RNF-101):
 * el titular (PA) y el representante (REP) solo alcanzan los registros de sus sujetos. Con el uuid
 * de otro paciente de la misma clínica o de otra clínica, toda ruta PORT responde 404. T-015.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PortalFixtures;

beforeEach(fn () => Storage::fake('s3'));

/**
 * Paciente adulto con un presupuesto emitido y una cita, en la clínica indicada.
 *
 * @return array{'{patient}': string, '{budget}': string, '{appointment}': string, '{tooth}': string}
 */
function portalForeignRecords(Tenant $tenant): array
{
    [, $patient] = PortalFixtures::holder($tenant);
    $budget = PortalFixtures::issuedBudget($tenant, $patient);
    $appointment = TenantContext::run($tenant, fn () => Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'starts_at' => now()->addDays(5),
    ]));

    $consent = TenantContext::run($tenant, fn () => Consent::query()->where('patient_id', $patient->id)->where('status', 'vigente')->sole());

    return ['{patient}' => $patient->uuid, '{budget}' => $budget, '{appointment}' => $appointment->uuid, '{consent}' => $consent->uuid, '{tooth}' => '16'];
}

/** @return list<array{0: string, 1: string}> Rutas PORT que reciben el uuid de un registro. */
function portalRecordRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/portal/') && preg_match('/\{(patient|budget|appointment)\}/', $route->uri()) === 1)
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')->map(fn ($method) => [$method, $route->uri()]))
        ->values()
        ->all();
}

it('returns 404 to the holder and the representative for the records of other patients', function (string $scope) {
    config(['auth.api_requests_per_minute' => 1000]);
    [$own, $other] = Tenant::factory()->count(2)->create();
    foreach ([$own, $other] as $tenant) {
        TenantContext::run($tenant, fn () => ClinicSetting::query()->sole()->forceFill(['self_booking_enabled' => true])->save());
    }

    [$holder] = PortalFixtures::holder($own);
    $representative = PortalFixtures::representative($own, PortalFixtures::minor($own));
    $bindings = portalForeignRecords($scope === 'otro paciente' ? $own : $other);
    $routes = portalRecordRoutes();

    expect(count($routes))->toBeGreaterThanOrEqual(13);

    /** @var User $user */
    foreach (['PA' => $holder, 'REP' => $representative] as $column => $user) {
        $this->actingWithToken($user);

        foreach ($routes as [$method, $uri]) {
            $path = '/'.strtr($uri, $bindings);
            $status = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

            expect($status)->toBe(404, "{$column}: {$method} {$path} respondió {$status}");
        }
    }
})->with(['otro paciente', 'otra clínica'])->group('T-015', 'RN-03', 'RF-003', 'RNF-101');

it('lets the representative reach only the current represented minors', function () {
    $tenant = Tenant::factory()->create();
    $minor = PortalFixtures::minor($tenant);
    $representative = PortalFixtures::representative($tenant, $minor);
    $sibling = PortalFixtures::minor($tenant, '2017-09-09');
    $this->actingWithToken($representative);

    $this->getJson("/api/v1/portal/patients/{$minor->uuid}/appointments")->assertOk();
    $this->getJson("/api/v1/portal/patients/{$sibling->uuid}/appointments")->assertNotFound();

    TenantContext::run($tenant, fn () => PortalFixtures::representation($tenant, $minor)->forceFill([
        'valid_until' => now()->toDateString(), 'ended_reason' => 'revocada',
    ])->save());
    $this->getJson("/api/v1/portal/patients/{$minor->uuid}/appointments")->assertNotFound();
})->group('RN-03', 'RN-13', 'RF-061');

it('keeps the portal account away from the records of its own clinic outside the portal', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $this->actingWithToken($user);

    $this->getJson('/api/v1/patients')->assertForbidden();
    $this->getJson("/api/v1/patients/{$patient->uuid}/budgets")->assertForbidden();
    $this->getJson('/api/v1/appointments')->assertForbidden();
})->group('RN-06', 'DI-13');
