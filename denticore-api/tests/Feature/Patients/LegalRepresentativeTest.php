<?php

/*
 * Representante legal (TASK-034; CUS-16, SDD §2.5, §1.9; RF-059, RF-060, RF-061, RN-12, RN-13,
 * DD-13).
 */

use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @return array<string, mixed> */
function representativeData(array $overrides = []): array
{
    return [
        'document_type' => 'dni',
        'document_number' => '41234567',
        'first_name' => 'Rosa',
        'last_name' => 'Mamani Soto',
        'relationship' => 'madre',
        'phone' => '912345678',
        'email' => 'rosa@correo.test',
        'valid_from' => '2026-10-05',
        ...$overrides,
    ];
}

function minorOf(Tenant $tenant, string $birthDate = '2016-03-14'): Patient
{
    return TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create(['birth_date' => $birthDate]));
}

it('registers a representative and lists the current ones', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = minorOf($tenant);

    $this->postJson("/api/v1/patients/{$patient->uuid}/representatives", representativeData())
        ->assertCreated()
        ->assertJsonPath('data.first_name', 'Rosa')
        ->assertJsonPath('data.relationship', 'madre')
        ->assertJsonPath('data.document_number', '41234567')
        ->assertJsonPath('data.valid_until', null);

    $this->getJson("/api/v1/patients/{$patient->uuid}/representatives")->assertOk()->assertJsonCount(1, 'data');
    expect(AuditLog::query()->where('action', 'representative.created')->sole()->patient_uuid)->toBe($patient->uuid);
})->group('RF-060', 'CUS-16');

it('lets one person represent two children', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    [$first, $second] = [minorOf($tenant), minorOf($tenant, '2018-07-01')];

    $this->postJson("/api/v1/patients/{$first->uuid}/representatives", representativeData())->assertCreated();
    $this->postJson("/api/v1/patients/{$second->uuid}/representatives", representativeData())->assertCreated();

    $hashes = TenantContext::run($tenant, fn () => LegalRepresentative::query()->pluck('document_hash')->unique());
    expect($hashes)->toHaveCount(1);
})->group('RF-060');

it('does not store the representative document or phone in clear text', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = minorOf($tenant);

    $this->postJson("/api/v1/patients/{$patient->uuid}/representatives", representativeData())->assertCreated();

    $row = TenantContext::run($tenant, fn () => DB::table('legal_representatives')->sole());
    expect($row->document_number)->toStartWith('v1:')->not->toContain('41234567')
        ->and($row->phone)->toStartWith('v1:')->not->toContain('912345678');
})->group('RNF-002', 'DD-04');

it('validates the representative data', function (array $overrides, string $field) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = minorOf($tenant);

    $this->postJson("/api/v1/patients/{$patient->uuid}/representatives", representativeData($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'DNI de 7 dígitos' => [['document_number' => '4123456'], 'document_number'],
    'parentesco inexistente' => [['relationship' => 'abuela'], 'relationship'],
    'teléfono que no empieza en 9' => [['phone' => '812345678'], 'phone'],
    'tipo de documento inexistente' => [['document_type' => 'ruc'], 'document_type'],
])->group('RF-060');

it('ends a representation with a reason', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $patient = minorOf($tenant);
    $id = $this->postJson("/api/v1/patients/{$patient->uuid}/representatives", representativeData())->json('data.id');

    $this->postJson("/api/v1/patients/{$patient->uuid}/representatives/{$id}/end", ['reason' => 'revocada'])
        ->assertOk()
        ->assertJsonPath('data.ended_reason', 'revocada');

    expect(AuditLog::query()->where('action', 'representative.ended')->count())->toBe(1);
    $this->getJson("/api/v1/patients/{$patient->uuid}/representatives/".fake()->uuid().'/end')->assertStatus(405);
})->group('RF-061');

it('ends the representation the day the patient turns 18', function () {
    $tenant = Tenant::factory()->create();
    $patient = minorOf($tenant, '2008-10-06');
    TenantContext::run($tenant, fn () => DB::transaction(fn () => app(LegalRepresentativeService::class)->register($patient, representativeData(['valid_from' => '2020-01-01']))));

    // La víspera del cumpleaños 18 (hora de Lima) sigue vigente.
    $this->travelTo(Carbon::parse('2026-10-05 23:59:00', 'America/Lima'));
    $this->artisan('representations:end-at-majority')->assertSuccessful();
    expect(TenantContext::run($tenant, fn () => LegalRepresentative::query()->sole())->valid_until)->toBeNull();

    // A las 00:05 del día en que cumple 18 años.
    $this->travelTo(Carbon::parse('2026-10-06 00:05:00', 'America/Lima'));
    $this->artisan('representations:end-at-majority')->assertSuccessful();

    $representation = TenantContext::run($tenant, fn () => LegalRepresentative::query()->sole());
    expect($representation->valid_until->toDateString())->toBe('2026-10-06')
        ->and($representation->ended_reason)->toBe('mayoria_de_edad');
})->group('RF-061', 'RN-13');

it('runs inside the transaction of the calling service without opening its own', function () {
    $tenant = Tenant::factory()->create();
    $patient = minorOf($tenant);

    try {
        TenantContext::run($tenant, fn () => DB::transaction(function () use ($patient) {
            app(LegalRepresentativeService::class)->register($patient, representativeData());
            throw new RuntimeException('falla el registro del paciente');
        }));
    } catch (RuntimeException) {
    }

    // El rollback del Service que la invocó también deshace la representación: no abrió una
    // transacción propia que la confirmara.
    expect(TenantContext::run($tenant, fn () => LegalRepresentative::query()->count()))->toBe(0);
})->group('DD-13', 'RN-12');

it('forbids dentists from registering representatives', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = minorOf($tenant);

    $this->postJson("/api/v1/patients/{$patient->uuid}/representatives", representativeData())->assertForbidden();
    $this->getJson("/api/v1/patients/{$patient->uuid}/representatives")->assertOk();
})->group('RN-06', 'CUS-16');
