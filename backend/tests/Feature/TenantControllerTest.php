<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * technical_specs.md §5.1 (POST/GET /tenants) y §7.6 (test_only_super_admin_can_register_tenant).
 */
class TenantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_register_tenant(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/tenants', [
            'name' => 'Clinica Sonrisa',
            'slug' => 'clinica-sonrisa',
            'subscription_plan' => 'pro',
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'clinica-sonrisa');
        $this->assertDatabaseHas('tenants', ['slug' => 'clinica-sonrisa']);
    }

    public function test_non_super_admin_cannot_register_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $response = $this->actingAs($dentist, 'sanctum')->postJson('/api/v1/tenants', [
            'name' => 'Clinica Sonrisa',
            'slug' => 'clinica-sonrisa',
            'subscription_plan' => 'pro',
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_can_list_tenants(): void
    {
        Tenant::factory()->count(3)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/tenants');

        $response->assertOk()->assertJsonCount(3, 'data');
    }
}
