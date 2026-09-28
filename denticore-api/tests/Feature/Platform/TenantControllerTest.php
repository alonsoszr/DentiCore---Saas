<?php

namespace Tests\Feature\Platform;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Alta y listado de clínicas con el contrato heredado POST/GET /tenants (CUS-01).
 */
class TenantControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Clinica Sonrisa',
            'slug' => 'clinica-sonrisa',
            'subscription_plan' => 'pro',
            'admin' => [
                'name' => 'Ana Administradora',
                'email' => 'ana@clinica-sonrisa.test',
                'password' => 'secret-password',
            ],
        ], $overrides);
    }

    public function test_only_super_admin_can_register_tenant(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/tenants', $this->payload());

        $response->assertCreated()->assertJsonPath('data.slug', 'clinica-sonrisa');
        $this->assertDatabaseHas('tenants', ['slug' => 'clinica-sonrisa']);
    }

    public function test_registering_tenant_generates_its_encryption_key(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/tenants', $this->payload())->assertCreated();

        $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
        TenantContext::run($tenant, fn () => $this->assertDatabaseHas('encryption_keys', ['tenant_id' => $tenant->id, 'is_active' => true]));
    }

    public function test_registering_tenant_creates_its_first_clinic_admin_who_can_log_in(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/tenants', $this->payload(['admin' => ['role' => 'super_admin', 'tenant_id' => 999]]))
            ->assertCreated();

        $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
        $admin = User::query()->where('email', 'ana@clinica-sonrisa.test')->sole();
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertSame('clinic_admin', $admin->role);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'tenant_slug' => 'clinica-sonrisa',
            'email' => 'ana@clinica-sonrisa.test',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonPath('user.role', 'clinic_admin');
    }

    public function test_registering_tenant_requires_first_admin_data_and_creates_nothing_without_it(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $payload = $this->payload();
        unset($payload['admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/tenants', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin', 'admin.email', 'admin.password']);

        $this->assertDatabaseMissing('tenants', ['slug' => 'clinica-sonrisa']);
    }

    public function test_non_super_admin_cannot_register_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

        $response = $this->actingAs($dentist, 'sanctum')->postJson('/api/v1/tenants', $this->payload());

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
