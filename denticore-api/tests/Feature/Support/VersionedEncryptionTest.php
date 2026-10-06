<?php

/*
 * Cifrado AES-256-GCM versionado (TASK-013; SDD §1.7.1; DD-04, DI-06, RNF-091, RNF-132).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\EncryptionKey;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

function rawPatientRow(Patient $patient): object
{
    return TenantContext::run($patient->tenant_id, fn () => DB::table('patients')->where('id', $patient->id)->first());
}

it('stores encrypted values with the v1 envelope', function () {
    $patient = Patient::factory()->create(['document_number' => '45678912', 'phone' => '987654321']);
    $row = rawPatientRow($patient);

    expect($row->document_number)->toStartWith('v1:')
        ->and($row->clinical_record_number)->toStartWith('v1:')
        ->and($row->phone)->toStartWith('v1:')
        ->and(TenantContext::run($patient->tenant_id, fn () => $patient->fresh()->document_number))->toBe('45678912');
})->group('DD-04', 'DI-06');

it('creates each clinic key as version 1 active', function () {
    $tenant = Tenant::factory()->create();
    $key = TenantContext::run($tenant, fn () => EncryptionKey::query()->sole());

    expect($key->version)->toBe(1)
        ->and($key->status)->toBe('activa');
})->group('RF-048');

it('rejects a tampered ciphertext', function () {
    $tenant = Tenant::factory()->create();
    $encryption = app(TenantEncryption::class);
    $payload = $encryption->encrypt($tenant->id, '45678912');

    $binary = base64_decode(substr($payload, 3));
    $binary[20] = chr(ord($binary[20]) ^ 1);

    expect(fn () => $encryption->decrypt($tenant->id, 'v1:'.base64_encode($binary)))
        ->toThrow(DecryptException::class)
        ->and(fn () => $encryption->decrypt($tenant->id, 'v1:no-es-base64'))
        ->toThrow(DecryptException::class);
})->group('DI-06');

it('does not decrypt with the key of another clinic', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $encryption = app(TenantEncryption::class);

    expect(fn () => $encryption->decrypt($tenantB->id, $encryption->encrypt($tenantA->id, '45678912')))
        ->toThrow(DecryptException::class);
})->group('DD-04', 'RNF-091');

it('no longer decrypts the legacy format of phases 0 to 3 (TASK-038)', function () {
    $tenant = Tenant::factory()->create();
    $legacy = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('45678912');

    expect(fn () => app(TenantEncryption::class)->decrypt($tenant->id, $legacy))->toThrow(DecryptException::class);
})->group('RNF-132', 'DD-04');
