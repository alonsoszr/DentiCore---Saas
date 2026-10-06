<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * CUS-11 heredado (GET/POST/PATCH /users) y aislamiento explícito de `users` (SDD §3.7)
 * ("Gestionar usuarios de la clínica": solo clinic_admin).
 */
class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $clinicAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->clinicAdmin = User::factory()->for($this->tenant)->create(['role' => 'clinic_admin']);
    }

    public function test_clinic_admin_lists_only_users_of_own_clinic(): void
    {
        $colleague = User::factory()->for($this->tenant)->create(['role' => 'dentist']);
        User::factory()->for(Tenant::factory())->create(['role' => 'dentist']);

        $response = $this->actingAs($this->clinicAdmin, 'sanctum')->getJson('/api/v1/users');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [$this->clinicAdmin->uuid, $colleague->uuid],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_created_user_belongs_to_admin_clinic_even_if_payload_sends_other_tenant_id(): void
    {
        $otherTenant = Tenant::factory()->create();

        $response = $this->actingAs($this->clinicAdmin, 'sanctum')->postJson('/api/v1/users', [
            'tenant_id' => $otherTenant->id,
            'name' => 'Dra. Pérez',
            'email' => 'perez@clinica.test',
            'password' => 'password-segura',
            'role' => 'dentist',
            'cop_number' => '12345',
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'dentist');
        $this->assertDatabaseHas('users', [
            'email' => 'perez@clinica.test',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_clinic_admin_cannot_create_super_admin(): void
    {
        $response = $this->actingAs($this->clinicAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Intruso',
            'email' => 'intruso@clinica.test',
            'password' => 'password-segura',
            'role' => 'super_admin',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'intruso@clinica.test']);
    }

    public function test_email_is_unique_per_clinic_but_reusable_across_clinics(): void
    {
        User::factory()->for(Tenant::factory())->create(['email' => 'compartido@test.test']);

        $this->actingAs($this->clinicAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Primera',
            'email' => 'compartido@test.test',
            'password' => 'password-segura',
            'role' => 'receptionist',
        ])->assertCreated();

        $this->actingAs($this->clinicAdmin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Duplicada',
            'email' => 'compartido@test.test',
            'password' => 'password-segura',
            'role' => 'receptionist',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_clinic_admin_can_update_user_of_own_clinic(): void
    {
        $user = User::factory()->for($this->tenant)->create(['role' => 'receptionist']);

        $response = $this->actingAs($this->clinicAdmin, 'sanctum')
            ->patchJson("/api/v1/users/{$user->uuid}", ['role' => 'dentist', 'cop_number' => '54321']);

        $response->assertOk()->assertJsonPath('data.role', 'dentist');
        $this->assertSame('dentist', $user->fresh()->role);
    }

    public function test_clinic_admin_cannot_update_user_of_another_clinic(): void
    {
        $foreignUser = User::factory()->for(Tenant::factory())->create(['role' => 'dentist']);

        $response = $this->actingAs($this->clinicAdmin, 'sanctum')
            ->patchJson("/api/v1/users/{$foreignUser->uuid}", ['name' => 'Modificado']);

        $response->assertNotFound();
        $this->assertNotSame('Modificado', $foreignUser->fresh()->name);
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $user = User::factory()->for($this->tenant)->create(['role' => 'dentist']);
        $user->createToken('api');

        $this->actingAs($this->clinicAdmin, 'sanctum')
            ->patchJson("/api/v1/users/{$user->uuid}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_only_clinic_admin_can_manage_users(): void
    {
        $dentist = User::factory()->for($this->tenant)->create(['role' => 'dentist']);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($dentist, 'sanctum')->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/users')->assertForbidden();
    }
}
