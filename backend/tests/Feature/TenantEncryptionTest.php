<?php

namespace Tests\Feature;

use App\Models\EncryptionKey;
use App\Models\Patient;
use App\Models\Tenant;
use App\Services\Encryption\TenantEncryption;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * technical_specs.md §2.3 punto 4 y §7.8 — Cifrado en reposo con clave por clínica.
 */
class TenantEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_document_id_is_encrypted_at_rest(): void
    {
        $patient = Patient::factory()->create(['document_id' => '45678912', 'phone' => '987654321']);

        $row = DB::table('patients')->where('id', $patient->id)->first();

        $this->assertNotSame('45678912', $row->document_id);
        $this->assertStringNotContainsString('45678912', $row->document_id);
        $this->assertNotSame('987654321', $row->phone);
        $this->assertStringNotContainsString('45678912', $row->document_id_hash);

        $this->assertSame('45678912', $patient->fresh()->document_id);
        $this->assertSame('987654321', $patient->fresh()->phone);
    }

    public function test_tenant_key_is_stored_encrypted_with_master_key(): void
    {
        $tenant = Tenant::factory()->create();

        $key = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();

        $this->assertTrue($key->is_active);
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
