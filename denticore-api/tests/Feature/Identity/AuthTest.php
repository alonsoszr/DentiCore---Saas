<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Login, logout y /auth/me heredados de las fases 0–3 (CUS-06, CUS-10), con
 * tenant_slug porque el email es único por clínica (SDD §1.6.2, §3.7).
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

        $response->assertOk()->assertJsonPath('user.id', $user->uuid);
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

        $response->assertOk()->assertJsonPath('data.id', $user->uuid);
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
