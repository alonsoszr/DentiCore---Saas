<?php

namespace Tests\Feature;

use App\Models\EncryptionKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * technical_specs.md §7.1 — Aislamiento multi-tenant (crítico).
 *
 * Se ejercita el mecanismo (trait BelongsToTenant + TenantScope) sobre EncryptionKey,
 * un modelo de datos de clínica real (§2.3 punto 4). No se usa User: Sanctum resuelve
 * el usuario dueño de un token antes de que exista un tenant activo en el contenedor,
 * así que User deliberadamente no lleva el Global Scope (ver nota en app/Models/User.php).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_filters_all_queries_by_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $keyA = EncryptionKey::factory()->for($tenantA)->create();
        EncryptionKey::factory()->for($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $results = EncryptionKey::all();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($keyA));
    }

    public function test_tenant_id_is_never_accepted_from_request_payload(): void
    {
        $tenantA = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        app()->instance('currentTenant', $tenantA);

        $key = EncryptionKey::create([
            'tenant_id' => $otherTenant->id,
            'key_ciphertext' => 'intento-de-inyeccion',
        ]);

        $this->assertSame($tenantA->id, $key->fresh()->tenant_id);
    }

    public function test_super_admin_can_bypass_tenant_scope_only_explicitly(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        EncryptionKey::factory()->for($tenantA)->create();
        EncryptionKey::factory()->for($tenantB)->create();

        // Sin tenant activo resuelto (caso super_admin): la query no debe filtrar
        // implícitamente todo, debe devolver cero resultados por defecto.
        $this->assertCount(0, EncryptionKey::all());

        // El bypass debe ser explícito.
        $this->assertCount(2, EncryptionKey::withoutTenantScope()->get());
    }
}
