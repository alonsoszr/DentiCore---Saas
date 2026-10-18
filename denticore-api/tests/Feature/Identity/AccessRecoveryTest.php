<?php

/*
 * Restablecer el acceso de un usuario (TASK-101; CUS-79; SDD §3.6, §4.3.2; RF-052, RN-04,
 * DD-36, RN-67). T-025.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

const RECOVERY_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function lockedUser(Tenant $tenant, string $role = 'receptionist'): User
{
    return User::factory()->for($tenant)->create([
        'role' => $role,
        'status' => 'bloqueado_temporal',
        'failed_login_count' => 0,
        'locked_until' => now()->addMinutes(15),
        'password' => 'Clave-Segura-2026',
    ]);
}

function withTwoFactor(User $user): User
{
    $user->forceFill(['two_factor_secret' => RECOVERY_TOTP_SECRET, 'two_factor_confirmed_at' => now()])->save();
    DB::table('two_factor_recovery_codes')->insert([
        'user_id' => $user->id, 'code_hash' => hash('sha256', 'AAAAA-BBBBB'), 'created_at' => now(),
    ]);
    $user->createToken('sesion', ['full']);

    return $user;
}

function auditRows(Tenant $tenant, string $action)
{
    return TenantContext::run($tenant, fn () => AuditLog::query()->where('action', $action)->get());
}

it('unlocks a locked user of the own clinic so the user can log in again', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-acceso']);
    $locked = lockedUser($tenant);
    $this->actingAsRole('clinic_admin', $tenant);

    $this->postJson("/api/v1/users/{$locked->uuid}/unlock")
        ->assertOk()
        ->assertJsonPath('data.status', 'activo');

    expect($locked->refresh()->locked_until)->toBeNull()
        ->and(auditRows($tenant, 'user.unlocked'))->toHaveCount(1);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-acceso', 'email' => $locked->email, 'password' => 'Clave-Segura-2026'])
        ->assertOk();
})->group('RF-052', 'CUS-79', 'RN-67');

it('answers 409 when the user is not locked', function () {
    $tenant = Tenant::factory()->create();
    $active = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $this->actingAsRole('clinic_admin', $tenant);

    $this->postJson("/api/v1/users/{$active->uuid}/unlock")
        ->assertConflict()
        ->assertJsonPath('rule', 'RF-052');
})->group('RF-052', 'CUS-79');

it('resets the second factor of a user of the own clinic and closes the sessions', function () {
    $tenant = Tenant::factory()->create();
    $dentist = withTwoFactor(User::factory()->for($tenant)->create(['role' => 'dentist']));
    $this->actingAsRole('clinic_admin', $tenant);

    $this->postJson("/api/v1/users/{$dentist->uuid}/2fa/reset")->assertOk();

    $dentist->refresh();
    expect($dentist->two_factor_secret)->toBeNull()
        ->and($dentist->two_factor_confirmed_at)->toBeNull()
        ->and($dentist->two_factor_reset_required)->toBeTrue()
        ->and($dentist->tokens()->count())->toBe(0)
        ->and(DB::table('two_factor_recovery_codes')->where('user_id', $dentist->id)->count())->toBe(0);

    $row = auditRows($tenant, 'auth.2fa_reset')->sole();
    expect($row->metadata)->toBe(['origen' => 'administrador'])
        ->and(json_encode($row->toArray()))->not->toContain(RECOVERY_TOTP_SECRET);
})->group('RF-052', 'DD-36', 'CUS-79', 'RN-67');

it('asks to configure the second factor again on the next login after a reset', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-reinicio']);
    $receptionist = withTwoFactor(User::factory()->for($tenant)->create(['role' => 'receptionist', 'password' => 'Clave-Segura-2026']));
    $this->actingAsRole('clinic_admin', $tenant);
    $this->postJson("/api/v1/users/{$receptionist->uuid}/2fa/reset")->assertOk();

    // Criterio 2 de TASK-101: el siguiente login recibe un token 2fa:setup (SDD §3.6).
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-reinicio', 'email' => $receptionist->email, 'password' => 'Clave-Segura-2026'])
        ->assertOk()
        ->assertJsonPath('abilities', ['2fa:setup'])
        ->assertJsonPath('requires_2fa_setup', true);
})->group('RF-052', 'CUS-79', 'DD-36');

it('does not let a clinic admin recover users of another clinic', function (string $action) {
    $other = lockedUser(Tenant::factory()->create());
    $this->actingAsRole('clinic_admin');

    $this->postJson("/api/v1/users/{$other->uuid}/{$action}")->assertNotFound();

    expect($other->refresh()->status)->toBe('bloqueado_temporal');
})->with(['unlock', '2fa/reset'])->group('RF-052', 'RN-01', 'CUS-79');

it('lets the super admin unlock and reset the second factor of a clinic admin', function () {
    $tenant = Tenant::factory()->create();
    $admin = withTwoFactor(lockedUser($tenant, 'clinic_admin'));
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/clinic-admins/{$admin->uuid}/unlock")->assertOk()->assertJsonPath('data.status', 'activo');
    $this->postJson("/api/v1/platform/clinic-admins/{$admin->uuid}/2fa/reset")->assertOk();

    expect($admin->refresh()->two_factor_reset_required)->toBeTrue()
        ->and(auditRows($tenant, 'user.unlocked'))->toHaveCount(1)
        ->and(auditRows($tenant, 'auth.2fa_reset')->sole()->metadata)->toBe(['origen' => 'plataforma']);
})->group('RF-052', 'RN-04', 'CUS-79', 'RN-67');

it('forbids super_admin from resetting 2FA of a dentist', function (string $role) {
    $user = withTwoFactor(User::factory()->for(Tenant::factory()->create())->create(['role' => $role]));
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/clinic-admins/{$user->uuid}/2fa/reset")
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-04');
    $this->postJson("/api/v1/platform/clinic-admins/{$user->uuid}/unlock")->assertForbidden();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
})->with(['dentist', 'receptionist', 'patient'])->group('T-025', 'RF-052', 'RN-04', 'CUS-79');

it('answers 404 for an unknown user in the platform routes', function () {
    $this->actingAsRole('super_admin');

    $this->postJson('/api/v1/platform/clinic-admins/'.fake()->uuid().'/unlock')->assertNotFound();
})->group('RF-052', 'CUS-79');
