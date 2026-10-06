<?php

namespace Tests\Feature\Support;

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\EncryptionKey;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Cifrado en reposo con clave por clínica (SDD §1.7.1, DD-04).
 */
class TenantEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_document_is_encrypted_at_rest(): void
    {
        $patient = Patient::factory()->create(['document_number' => '45678912', 'phone' => '987654321']);

        TenantContext::run($patient->tenant_id, function () use ($patient) {
            $row = DB::table('patients')->where('id', $patient->id)->first();

            $this->assertNotSame('45678912', $row->document_number);
            $this->assertStringNotContainsString('45678912', $row->document_number);
            $this->assertNotSame('987654321', $row->phone);
            $this->assertStringNotContainsString('45678912', $row->document_hash);

            $this->assertSame('45678912', $patient->fresh()->document_number);
            $this->assertSame('987654321', $patient->fresh()->phone);
        });
    }

    public function test_tenant_key_is_stored_encrypted_with_master_key(): void
    {
        $tenant = Tenant::factory()->create();

        $key = TenantContext::run($tenant, fn () => EncryptionKey::query()->sole());

        $this->assertSame('activa', $key->status);
        $this->assertSame(32, strlen(base64_decode(decrypt($key->key_ciphertext, false))));
    }

    public function test_data_encrypted_with_one_clinic_key_cannot_be_decrypted_with_another(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $encryption = app(TenantEncryption::class);

        $payload = $encryption->encrypt($tenantA->id, '45678912');

        $this->assertSame('45678912', $encryption->decrypt($tenantA->id, $payload));

        $this->expectException(DecryptException::class);
        $encryption->decrypt($tenantB->id, $payload);
    }

    public function test_blind_index_differs_between_clinics_for_same_value(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $encryption = app(TenantEncryption::class);

        $this->assertSame(
            $encryption->blindIndex($tenantA->id, '45678912'),
            $encryption->blindIndex($tenantA->id, '45678912'),
        );
        $this->assertNotSame(
            $encryption->blindIndex($tenantA->id, '45678912'),
            $encryption->blindIndex($tenantB->id, '45678912'),
        );
    }
}
