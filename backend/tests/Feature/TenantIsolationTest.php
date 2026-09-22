<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * technical_specs.md §7.1 — Aislamiento multi-tenant (crítico).
 *
 * Se ejercita el mecanismo (trait BelongsToTenant + TenantScope) sobre Patient. No se usa
 * User: Sanctum resuelve el usuario dueño de un token antes de que exista un tenant activo
 * en el contenedor, así que User deliberadamente no lleva el Global Scope (ver nota en
 * app/Models/User.php). test_user_cannot_access_patient_from_another_tenant está en
 * PatientControllerTest, porque se prueba por HTTP.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_filters_all_queries_by_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $patientA = Patient::factory()->for($tenantA)->create();
        Patient::factory()->for($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $results = Patient::all();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($patientA));
    }

    public function test_tenant_id_is_never_accepted_from_request_payload(): void
    {
        $tenantA = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        app()->instance('currentTenant', $tenantA);

        $patient = Patient::create([
            'tenant_id' => $otherTenant->id,
            'document_id' => '12345678',
            'first_name' => 'Ana',
            'last_name' => 'Quispe',
            'birth_date' => '1990-05-10',
        ]);

        $this->assertSame($tenantA->id, $patient->fresh()->tenant_id);
    }

    public function test_super_admin_can_bypass_tenant_scope_only_explicitly(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Patient::factory()->for($tenantA)->create();
        Patient::factory()->for($tenantB)->create();

        // Sin tenant activo resuelto (caso super_admin): la query no debe filtrar
        // implícitamente todo, debe devolver cero resultados por defecto.
        $this->assertCount(0, Patient::all());

        // El bypass debe ser explícito.
        $this->assertCount(2, Patient::withoutTenantScope()->get());
    }
}
