<?php

/*
 * Aislamiento de las rutas, jobs, PDF y búsquedas de MS-01 (TASK-042; SDD §1.6, §6.3; RN-01,
 * RN-03, RF-003, RNF-101). T-015 y T-016.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Jobs\EndRepresentationsAtMajorityJob;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ficha de un menor con su representante y su consentimiento en la clínica indicada.
 *
 * @return array{patient: Patient, representative: LegalRepresentative, consent: Consent}
 */
function foreignClinicRecord(Tenant $tenant, string $birthDate = '2015-01-01'): array
{
    return TenantContext::run($tenant, function () use ($tenant, $birthDate) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => $birthDate, 'last_name' => 'Núñez']);
        $representative = app(LegalRepresentativeService::class)->add($patient, [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
            'relationship' => 'madre', 'phone' => '912345678', 'email' => null, 'valid_from' => '2020-01-01',
        ]);
        $assistant = User::factory()->for($tenant)->create(['role' => 'receptionist']);
        $consent = app(ConsentService::class)->grant(
            $patient,
            ['channel' => 'presencial', 'confirmation_document_number' => '41234567'],
            $assistant,
            '127.0.0.1',
        );

        return ['patient' => $patient, 'representative' => $representative, 'consent' => $consent];
    });
}

beforeEach(fn () => Storage::fake('s3'));

it('returns 404 for every route when the uuid belongs to another clinic', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('clinic_admin', $own);
    $foreign = foreignClinicRecord($other);
    $bindings = [
        '{patient}' => $foreign['patient']->uuid,
        '{representative}' => $foreign['representative']->uuid,
        '{consent}' => $foreign['consent']->uuid,
        '{user}' => User::factory()->for($other)->create(['role' => 'receptionist'])->uuid,
    ];

    // Toda ruta de la clínica que recibe el uuid de un registro (RF-003).
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/')
            && preg_match('/\{(patient|representative|consent|user)\}/', $route->uri()) === 1)
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, $route->uri()]))
        ->values();

    expect($routes->count())->toBeGreaterThanOrEqual(15);

    foreach ($routes as [$method, $uri]) {
        $path = '/'.strtr($uri, $bindings);
        $status = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

        expect($status)->toBe(404, "{$method} {$path} respondió {$status}");
    }
})->group('T-015', 'RN-03', 'RF-003', 'RNF-101');

it('searches and looks up patients only within their clinic', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('receptionist', $own);
    $mine = TenantContext::run($own, fn () => Patient::factory()->for($own)->create(['last_name' => 'Núñez']));
    $foreign = TenantContext::run($other, fn () => Patient::factory()->for($other)->create([
        'last_name' => 'Núñez', 'document_number' => '87654321',
    ]));

    expect($this->getJson('/api/v1/patients?q=nunez')->assertOk()->json('data.*.id'))->toBe([$mine->uuid]);
    $this->getJson('/api/v1/patients/lookup?document_type=dni&document_number=87654321')->assertNotFound();
    expect($foreign->tenant_id)->toBe($other->id);
})->group('T-016', 'RN-01', 'RF-054');

it('generates the consent certificate PDF inside the clinic of the consent', function () {
    $other = Tenant::factory()->create();
    $foreign = foreignClinicRecord($other);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    TenantContext::run($other, function () use ($foreign, $other) {
        $file = $foreign['consent']->fresh()->certificate->storedFile;

        expect($file->tenant_id)->toBe($other->id)
            ->and($file->path)->toStartWith("tenants/{$other->uuid}/")
            ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
    });
})->group('T-016', 'RF-066', 'RNF-101');

it('ends representations at majority only for the clinic of the job', function () {
    // Menores que cumplen 18 años mañana; el job corre ese día.
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00', 'America/Lima'));
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = foreignClinicRecord($own, '2008-10-07');
    $theirs = foreignClinicRecord($other, '2008-10-07');
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00', 'America/Lima'));

    dispatch_sync(new EndRepresentationsAtMajorityJob($own->id));

    expect(TenantContext::run($own, fn () => $mine['representative']->fresh()->ended_reason))->toBe('mayoria_de_edad')
        ->and(TenantContext::run($other, fn () => $theirs['representative']->fresh()->valid_until))->toBeNull();
})->group('T-016', 'RN-13', 'RNF-101');
