<?php

/*
 * Cifrado AES-256-GCM versionado (TASK-013; SDD §1.7.1; DD-04, DI-06, RNF-091, RNF-132).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\EncryptionKey;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\KeyRing;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * Reescribe una ficha con el formato de las fases 0–3: AES-256-CBC de Laravel con la clave
 * directa de la clínica e índice ciego HMAC(HMAC(clave, 'blind-index'), valor).
 */
function writeLegacyPatient(Patient $patient, string $document, ?string $phone): void
{
    $rawKey = app(KeyRing::class)->rawKey($patient->tenant_id, 1);
    $legacy = new Encrypter($rawKey, 'aes-256-cbc');
    $legacyIndexKey = hash_hmac('sha256', 'blind-index', $rawKey, true);

    TenantContext::run($patient->tenant_id, fn () => DB::table('patients')->where('id', $patient->id)->update([
        'document_id' => $legacy->encryptString($document),
        'document_id_hash' => hash_hmac('sha256', $document, $legacyIndexKey),
        'phone' => $phone === null ? null : $legacy->encryptString($phone),
    ]));
}

function rawPatientRow(Patient $patient): object
{
    return TenantContext::run($patient->tenant_id, fn () => DB::table('patients')->where('id', $patient->id)->first());
}

it('stores encrypted values with the v1 envelope', function () {
    $patient = Patient::factory()->create(['document_id' => '45678912', 'phone' => '987654321']);
    $row = rawPatientRow($patient);

    expect($row->document_id)->toStartWith('v1:')
        ->and($row->phone)->toStartWith('v1:')
        ->and(TenantContext::run($patient->tenant_id, fn () => $patient->fresh()->document_id))->toBe('45678912');
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

it('re-encrypts legacy values to v1 once and leaves them unchanged on a second run', function () {
    $patient = Patient::factory()->create(['document_id' => '11112222', 'phone' => '911222333']);
    $current = Patient::factory()->for(Tenant::factory())->create(['document_id' => '33334444', 'phone' => null]);
    writeLegacyPatient($patient, '11112222', '911222333');

    // El formato heredado se sigue leyendo antes de convertirlo.
    expect(TenantContext::run($patient->tenant_id, fn () => $patient->fresh()->document_id))->toBe('11112222');
    $currentBefore = rawPatientRow($current);

    $this->artisan('encryption:reencrypt-legacy')->expectsOutput('Registros recifrados: 1')->assertSuccessful();

    $afterFirst = rawPatientRow($patient);
    expect($afterFirst->document_id)->toStartWith('v1:')
        ->and($afterFirst->phone)->toStartWith('v1:')
        ->and($afterFirst->document_id_hash)->toBe(app(TenantEncryption::class)->blindIndex($patient->tenant_id, '11112222'))
        ->and(TenantContext::run($patient->tenant_id, fn () => $patient->fresh()->document_id))->toBe('11112222')
        ->and(TenantContext::run($patient->tenant_id, fn () => $patient->fresh()->phone))->toBe('911222333')
        ->and(rawPatientRow($current))->toEqual($currentBefore);

    $this->artisan('encryption:reencrypt-legacy')->expectsOutput('Registros recifrados: 0')->assertSuccessful();

    expect(rawPatientRow($patient))->toEqual($afterFirst);
})->group('RNF-132', 'DD-04');
