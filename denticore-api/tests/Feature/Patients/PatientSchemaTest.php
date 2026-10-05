<?php

/*
 * Esquema de pacientes, etapa de expansión (TASK-031; SDD §2.5; DD-04, DI-07, DI-14, DI-19,
 * RN-09, RN-79, RNF-132, RNF-191).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientIdentity;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('fills the new identification columns of a patient registered with the legacy contract', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create(['document_id' => '45678912', 'first_name' => 'José', 'last_name' => 'Núñez Ñahui']);
        $raw = DB::table('patients')->where('id', $patient->id)->first();

        expect($raw->document_type)->toBe('dni')
            ->and($raw->document_number)->toStartWith('v1:')
            ->and($raw->clinical_record_number)->toStartWith('v1:')
            ->and($raw->search_name)->toBe('jose nunez nahui')
            ->and($raw->archive_status)->toBe('activo');

        $encryption = app(TenantEncryption::class);
        expect($patient->fresh())
            ->document_number->toBe('45678912')
            ->clinical_record_number->toBe('45678912');
        expect($raw->document_hash)->toBe($encryption->blindIndex($tenant->id, 'DNI:45678912'))
            ->and($raw->clinical_record_hash)->toBe($encryption->blindIndex($tenant->id, '45678912'));
    });
})->group('RN-09', 'RN-79', 'DD-04', 'DI-14');

it('normalizes the document and the clinical record number by document type', function (string $type, string $number, string $normalized, string $record) {
    expect(PatientIdentity::normalizedDocument($type, $number))->toBe($normalized)
        ->and(PatientIdentity::clinicalRecordNumber($type, $number))->toBe($record);
})->with([
    'DNI' => ['dni', '45678912', 'DNI:45678912', '45678912'],
    'carné de extranjería' => ['ce', 'ab123456 ', 'CE:AB123456', 'CE-AB123456'],
    'pasaporte' => ['pasaporte', 'x1234567', 'PAS:X1234567', 'PAS-X1234567'],
    'permiso temporal' => ['cpp', '123456789', 'CPP:123456789', 'CPP-123456789'],
])->group('RN-09', 'RN-79');

it('rejects changing the clinical record number', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create();

        expect(fn () => DB::transaction(fn () => DB::table('patients')->where('id', $patient->id)->update(['clinical_record_number' => 'v1:otro'])))
            ->toThrow(QueryException::class, 'immutable_clinical_record');
    });
})->group('RN-79', 'DI-07');

it('rejects a birth date before 1900-01-01', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create();

        expect(fn () => DB::transaction(fn () => DB::table('patients')->where('id', $patient->id)->update(['birth_date' => '1899-12-31'])))
            ->toThrow(QueryException::class, 'patients_birth_date_check');
    });
})->group('RN-12');

it('rejects a reference to a patient of another clinic through the composite foreign key', function () {
    [$sonrisa, $muela] = Tenant::factory()->count(2)->create();
    $foreign = TenantContext::run($muela, fn () => Patient::factory()->for($muela)->create());

    // La historia de identidad de la clínica Sonrisa no puede apuntar a un paciente de Muela.
    expect(fn () => TenantContext::run($sonrisa, fn () => DB::transaction(fn () => DB::table('patient_identity_history')->insert([
        'tenant_id' => $sonrisa->id,
        'patient_id' => $foreign->id,
        'changed_fields' => json_encode(['first_name']),
        'previous_values' => 'v1:x',
        'created_at' => now(),
    ]))))->toThrow(QueryException::class, 'foreign key');
})->group('DI-19', 'RN-01');

it('keeps the identity history immutable', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create();
        $id = DB::table('patient_identity_history')->insertGetId([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'changed_fields' => json_encode(['first_name']),
            'previous_values' => 'v1:x',
            'created_at' => now(),
        ]);

        expect(fn () => DB::transaction(fn () => DB::table('patient_identity_history')->where('id', $id)->update(['previous_values' => 'v1:y'])))
            ->toThrow(QueryException::class, 'immutable_row');
    });
})->group('RF-062', 'RN-69');
