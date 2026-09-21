<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cubre AuthController (technical_specs.md §5.1), incluyendo tenant_slug: extensión
 * necesaria no contemplada en el SDD original porque el email no es único globalmente
 * (ver nota en technical_specs.md y la migración de users).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_user_can_login_with_tenant_slug_email_and_password(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'clinica-sonrisa']);
        $user = User::factory()->for($tenant)->create([
            'email' => 'dentista@clinica-sonrisa.test',
            'password' => Hash::make('secret-password'),
            'role' => 'dentist',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'tenant_slug' => 'clinica-sonrisa',
            'email' => 'dentista@clinica-sonrisa.test',
            'password' => 'secret-password',
        ]);

        $response->assertOk()->assertJsonPath('user.uuid', $user->uuid);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_super_admin_can_login_without_tenant_slug(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@denticore.test',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@denticore.test',
            'password' => 'secret-password',
        ]);

        $response->assertOk()->assertJsonPath('user.role', 'super_admin');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'clinica-sonrisa']);
        User::factory()->for($tenant)->create([
            'email' => 'dentista@clinica-sonrisa.test',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'tenant_slug' => 'clinica-sonrisa',
            'email' => 'dentista@clinica-sonrisa.test',
            'password' => 'contraseña-incorrecta',
        ]);

        $response->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_own_profile_via_me_endpoint(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('data.uuid', $user->uuid);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
