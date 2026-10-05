<?php

/*
 * Registro y actualización de la identificación del paciente (TASK-032; CUS-14, CUS-15, SRS
 * §11.3, SDD §4.5; RF-055 a RF-059, RF-062, RN-09, RN-12, RN-79, DD-04). T-029 a T-032 y T-034.
 */

use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** @return array<string, mixed> */
function patientPayload(array $overrides = []): array
{
    return [
        'document_type' => 'dni',
        'document_number' => '45678912',
        'first_name' => 'Lucía',
        'last_name' => 'Quispe Mamani',
        'birth_date' => '1990-03-14',
        'sex' => 'femenino',
        'phone' => '987654321',
        'email' => null,
        'address' => 'Jr. Los Olivos 123, Lima',
        ...$overrides,
    ];
}

function registerPatient(array $payload): TestResponse
{
    return test()->postJson('/api/v1/patients', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('returns the existing patient instead of creating a duplicate document', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $existing = registerPatient(patientPayload())->assertCreated()->json('data.id');

    registerPatient(patientPayload(['document_number' => ' 45678912 ', 'first_name' => 'Otra']))
        ->assertUnprocessable()
        ->assertJsonPath('existing_patient_id', $existing)
        ->assertJsonValidationErrors(['document_number']);

    expect(TenantContext::run($tenant, fn () => Patient::query()->count()))->toBe(1);
})->group('CA-14.1', 'RN-09', 'RF-056');

it('allows the same DNI in another clinic without cross visibility', function () {
    [$sonrisa, $muela] = Tenant::factory()->count(2)->create();

    $this->actingAsRole('receptionist', $sonrisa);
    $first = registerPatient(patientPayload())->assertCreated()->json('data.id');

    $this->actingAsRole('receptionist', $muela);
    registerPatient(patientPayload())->assertCreated();
    $this->getJson("/api/v1/patients/{$first}")->assertNotFound();
    $this->getJson('/api/v1/patients')->assertJsonCount(1, 'data');
})->group('CA-14.2', 'RN-09', 'RN-03');

it('stores document, phone and address encrypted at rest', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);

    registerPatient(patientPayload())->assertCreated();

    $row = TenantContext::run($tenant, fn () => DB::table('patients')->sole());
    foreach (['document_number' => '45678912', 'phone' => '987654321', 'address' => 'Los Olivos', 'clinical_record_number' => '45678912'] as $column => $clear) {
        expect($row->{$column})->toStartWith('v1:')->not->toContain($clear);
    }
    expect($row->document_hash)->toBe(app(TenantEncryption::class)->blindIndex($tenant->id, 'DNI:45678912'));
})->group('CA-14.3', 'DD-04', 'RF-057');

it('does not save a minor without a current legal representative', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $this->travelTo(Carbon::parse('2026-10-05 10:00', 'America/Lima'));

    registerPatient(patientPayload(['birth_date' => '2014-03-14']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['representative']);
    expect(TenantContext::run($tenant, fn () => Patient::query()->count()))->toBe(0);

    $response = registerPatient(patientPayload([
        'birth_date' => '2014-03-14',
        'representative' => [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani Soto',
            'relationship' => 'madre', 'phone' => '912345678', 'email' => 'rosa@correo.pe', 'valid_from' => '2026-10-05',
        ],
    ]))->assertCreated()->assertJsonPath('data.is_minor', true)->assertJsonPath('data.age_years', 12);

    TenantContext::run($tenant, function () use ($response) {
        $patient = Patient::query()->where('uuid', $response->json('data.id'))->sole();
        expect(LegalRepresentative::query()->where('patient_id', $patient->id)->whereNull('valid_until')->count())->toBe(1);
    });
})->group('CA-14.4', 'RN-12', 'RF-059');

it('assigns the DNI as clinical record number and prefixes other document types', function (string $type, string $number, string $record) {
    $this->actingAsRole('receptionist', Tenant::factory()->create());

    registerPatient(patientPayload(['document_type' => $type, 'document_number' => $number]))
        ->assertCreated()
        ->assertJsonPath('data.clinical_record_number', $record);
})->with([
    'DNI' => ['dni', '45678912', '45678912'],
    'carné de extranjería' => ['ce', '001234567', 'CE-001234567'],
    'pasaporte' => ['pasaporte', 'ab123456', 'PAS-AB123456'],
    'CPP' => ['cpp', '123456789', 'CPP-123456789'],
])->group('RN-79', 'RF-058');

it('validates the data of SRS §11.3', function (array $overrides, string $field) {
    $this->actingAsRole('receptionist', Tenant::factory()->create());
    $this->travelTo(Carbon::parse('2026-10-05 10:00', 'America/Lima'));

    registerPatient(patientPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'DNI de 9 dígitos' => [['document_number' => '456789123'], 'document_number'],
    'pasaporte de 5 caracteres' => [['document_type' => 'pasaporte', 'document_number' => 'AB123'], 'document_number'],
    'fecha de nacimiento futura' => [['birth_date' => '2026-10-06'], 'birth_date'],
    'edad mayor de 120 años' => [['birth_date' => '1905-01-01'], 'birth_date'],
    'sexo no válido' => [['sex' => 'otro'], 'sex'],
    'teléfono fijo' => [['phone' => '014567890'], 'phone'],
    'dirección de 4 caracteres' => [['address' => 'Lima'], 'address'],
])->group('RF-055');

it('accepts names with accents, ñ, apostrophes and hyphens', function () {
    $this->actingAsRole('receptionist', Tenant::factory()->create());

    registerPatient(patientPayload(['first_name' => "María José D'Alessandro", 'last_name' => 'Núñez-Ñahui']))
        ->assertCreated()
        ->assertJsonPath('data.last_name', 'Núñez-Ñahui');
})->group('RF-055', 'RNF-191');

it('updates the identification keeping encrypted previous values and auditing only field names', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $id = registerPatient(patientPayload())->json('data.id');

    $this->patchJson("/api/v1/patients/{$id}", ['phone' => '911222333', 'address' => 'Av. Brasil 456, Lima'])
        ->assertOk()
        ->assertJsonPath('data.phone', '911222333')
        ->assertJsonPath('data.clinical_record_number', '45678912');

    TenantContext::run($tenant, function () use ($tenant) {
        $history = DB::table('patient_identity_history')->sole();
        expect(json_decode($history->changed_fields, true))->toEqualCanonicalizing(['phone', 'address'])
            ->and($history->previous_values)->toStartWith('v1:')->not->toContain('987654321');
        expect(json_decode(app(TenantEncryption::class)->decrypt($tenant->id, $history->previous_values), true))
            ->toMatchArray(['phone' => '987654321', 'address' => 'Jr. Los Olivos 123, Lima']);
    });

    $audit = AuditLog::query()->where('action', 'patient.identity_updated')->sole();
    expect($audit->changed_fields)->toEqualCanonicalizing(['phone', 'address'])
        ->and(json_encode($audit->toArray()))->not->toContain('911222333')->not->toContain('Brasil');
})->group('RF-062', 'RN-69', 'RN-67');

it('lets only clinic_admin and receptionist update the identification', function () {
    $tenant = Tenant::factory()->create();
    $patient = TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create());
    $this->actingAsRole('dentist', $tenant);

    $this->patchJson("/api/v1/patients/{$patient->uuid}", ['phone' => '911222333'])->assertForbidden();
})->group('CUS-15', 'RN-06');
