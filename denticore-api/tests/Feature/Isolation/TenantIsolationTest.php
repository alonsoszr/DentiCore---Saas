<?php

namespace Tests\Feature\Isolation;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Aislamiento multi-tenant (SDD §1.6; RN-01 a RN-03).
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

        TenantContext::set($tenantA);

        $results = Patient::all();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($patientA));
    }

    public function test_tenant_id_is_never_accepted_from_request_payload(): void
    {
        $tenantA = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        TenantContext::set($tenantA);

        $patient = new Patient([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Ana',
            'last_name' => 'Quispe',
            'birth_date' => '1990-05-10',
            'sex' => 'femenino',
            'phone' => '987654321',
        ]);
        $patient->forceFill([
            'document_type' => 'dni',
            'document_number' => '12345678',
            'created_by' => User::factory()->for($tenantA)->create(['role' => 'receptionist'])->id,
        ])->save();

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

        // El bypass del Global Scope debe ser explícito. Desde TASK-007 la RLS (DD-40) es la
        // segunda barrera: con el rol de la API y sin clínica, ni siquiera el bypass ve filas;
        // la vista de todas las clínicas queda para la conexión de plataforma (BYPASSRLS).
        $this->assertCount(0, Patient::withoutTenantScope()->get());
        $this->assertCount(1, TenantContext::run($tenantA, fn () => Patient::withoutTenantScope()->get()));
        $this->assertCount(1, TenantContext::run($tenantB, fn () => Patient::withoutTenantScope()->get()));
    }
}
