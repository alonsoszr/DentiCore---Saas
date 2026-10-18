<?php

/*
 * Perfil, contraseña y segundo factor propios (TASK-100; CUS-78; SDD §3.6, §4.3.2; RF-040,
 * RF-049, RF-051, DD-36, RN-67).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SessionTokenService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

const PROFILE_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function profileUser(string $role = 'dentist', bool $withTwoFactor = true): User
{
    $attributes = ['role' => $role, 'name' => 'Diego Quispe', 'password' => 'Clave-Segura-2026'];
    $user = $role === 'super_admin'
        ? User::factory()->superAdmin()->create($attributes)
        : User::factory()->for(Tenant::factory()->create())->create($attributes);

    if ($withTwoFactor) {
        $user->forceFill(['two_factor_secret' => PROFILE_TOTP_SECRET, 'two_factor_confirmed_at' => now()])->save();
    }

    return $user;
}

/** Token real de sesión (con su `expires_at` y su IP), como el que emite el login. */
function profileSessionToken(User $user, array $abilities = ['full']): string
{
    return app(SessionTokenService::class)->issue($user, $abilities, '127.0.0.1', 'Pest')->plainTextToken;
}

function profileTotp(): string
{
    return (new Google2FA)->getCurrentOtp(PROFILE_TOTP_SECRET);
}

it('changes the own name and records which field changed', function () {
    $user = profileUser();

    $this->withToken(profileSessionToken($user))->patchJson('/api/v1/me/profile', ['name' => 'Diego Quispe Huamán'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Diego Quispe Huamán');

    expect($user->refresh()->name)->toBe('Diego Quispe Huamán');
    $row = AuditLog::query()->where('action', 'user.updated')->sole();
    expect($row->changed_fields)->toBe(['name'])
        ->and(json_encode($row->toArray()))->not->toContain('Huamán');
})->group('RF-049', 'CUS-78', 'RN-67');

it('rejects an empty or too long name', function (string $name) {
    $this->withToken(profileSessionToken(profileUser()))->patchJson('/api/v1/me/profile', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
})->with(['', str_repeat('a', 151)])->group('RF-049', 'CUS-78');

it('rejects the password change with a wrong current password', function () {
    $user = profileUser();

    $this->withToken(profileSessionToken($user))->putJson('/api/v1/me/password', [
        'current_password' => 'no-es-la-clave',
        'password' => 'Otra-Clave-Larga-2026',
        'password_confirmation' => 'Otra-Clave-Larga-2026',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'La contraseña actual no es correcta.']);

    expect(Hash::check('Clave-Segura-2026', $user->refresh()->password))->toBeTrue();
})->group('RF-049', 'CUS-78');

it('rejects one of the last 5 passwords', function () {
    $user = profileUser();
    DB::table('user_password_histories')->insert([
        'user_id' => $user->id, 'password_hash' => Hash::make('Anterior-Clave-2025'), 'created_at' => now()->subMonth(),
    ]);

    $this->withToken(profileSessionToken($user))->putJson('/api/v1/me/password', [
        'current_password' => 'Clave-Segura-2026',
        'password' => 'Anterior-Clave-2025',
        'password_confirmation' => 'Anterior-Clave-2025',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'password' => 'La contraseña no puede ser igual a ninguna de tus 5 últimas contraseñas.',
    ]);
})->group('RF-040', 'CUS-78');

it('changes the password, keeps the current session and closes the others', function () {
    $user = profileUser();
    $other = profileSessionToken($user);
    $current = profileSessionToken($user);

    $this->withToken($current)->putJson('/api/v1/me/password', [
        'current_password' => 'Clave-Segura-2026',
        'password' => 'Nueva-Clave-Larga-2026',
        'password_confirmation' => 'Nueva-Clave-Larga-2026',
    ])->assertNoContent();

    expect(Hash::check('Nueva-Clave-Larga-2026', $user->refresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::findToken($current))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($other))->toBeNull()
        ->and(DB::table('user_password_histories')->where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'auth.password_changed')->count())->toBe(1);
})->group('RF-040', 'RF-049', 'CUS-78', 'RN-67');

it('regenerates the recovery codes with a valid TOTP and invalidates the previous ones', function () {
    $user = profileUser();
    $old = 'AAAAA-BBBBB';
    DB::table('two_factor_recovery_codes')->insert([
        'user_id' => $user->id, 'code_hash' => hash('sha256', $old), 'created_at' => now(),
    ]);

    $codes = $this->withToken(profileSessionToken($user))->postJson('/api/v1/auth/2fa/recovery-codes', ['code' => profileTotp()])
        ->assertOk()
        ->json('recovery_codes');

    expect($codes)->toHaveCount(10)
        ->and(DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->count())->toBe(10)
        ->and(AuditLog::query()->where('action', 'auth.2fa_configured')->count())->toBe(1);

    // Criterio 2 de TASK-100: tras regenerar, un código de recuperación anterior responde 422.
    $pending = profileSessionToken($user, ['2fa:pending']);
    app('auth')->forgetGuards();
    $this->withToken($pending)->postJson('/api/v1/auth/2fa/verify', ['recovery_code' => $old])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recovery_code');
})->group('RF-051', 'DD-36', 'CUS-78');

it('does not regenerate the recovery codes with a wrong code', function () {
    $user = profileUser();

    $this->withToken(profileSessionToken($user))->postJson('/api/v1/auth/2fa/recovery-codes', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->count())->toBe(0);
})->group('RF-051', 'CUS-78');

it('lets a dentist turn off the optional second factor with a valid TOTP', function () {
    $user = profileUser('dentist');
    DB::table('two_factor_recovery_codes')->insert([
        'user_id' => $user->id, 'code_hash' => hash('sha256', 'AAAAA-BBBBB'), 'created_at' => now(),
    ]);

    $this->withToken(profileSessionToken($user))->deleteJson('/api/v1/auth/2fa', ['code' => profileTotp()])->assertNoContent();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'auth.2fa_reset')->count())->toBe(1);
})->group('RF-051', 'CUS-78', 'RN-67');

it('keeps the second factor when the code is wrong', function () {
    $user = profileUser('receptionist');

    $this->withToken(profileSessionToken($user))->deleteJson('/api/v1/auth/2fa', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
})->group('RF-051', 'CUS-78');

it('forbids turning off the second factor where it is mandatory', function (string $role) {
    $user = profileUser($role);

    // Criterio 1 de TASK-100: DELETE /auth/2fa con token de clinic_admin responde 403.
    $this->withToken(profileSessionToken($user))->deleteJson('/api/v1/auth/2fa', ['code' => profileTotp()])
        ->assertForbidden()
        ->assertJsonPath('rule', 'RF-051');

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
})->with(['clinic_admin', 'super_admin'])->group('RF-051', 'DD-15', 'CUS-78');

it('requires a session that passed the second factor', function (string $method, string $uri) {
    $user = profileUser();

    $this->withToken(profileSessionToken($user, ['2fa:pending']))->json($method, $uri, ['name' => 'X', 'code' => profileTotp()])
        ->assertForbidden()
        ->assertJsonPath('rule', 'two_factor_required');
})->with([
    ['PATCH', '/api/v1/me/profile'],
    ['PUT', '/api/v1/me/password'],
    ['POST', '/api/v1/auth/2fa/recovery-codes'],
    ['DELETE', '/api/v1/auth/2fa'],
])->group('RF-037', 'CUS-78');
