<?php

/*
 * Segundo factor TOTP (TASK-028; CUS-07, CUS-08; SDD §1.7, §1.8, §3.6; RF-037, RF-038, DD-15,
 * DD-36). T-009.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function totp(string $secret = TOTP_SECRET): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/** Inicia sesión y devuelve la respuesta del login. */
function loginAs(User $user): array
{
    return test()->postJson('/api/v1/auth/login', array_filter([
        'tenant_slug' => $user->tenant?->slug, 'email' => $user->email, 'password' => 'clave-correcta-123',
    ]))->assertOk()->json();
}

function freshRequest(): void
{
    app('auth')->forgetGuards();
}

function clinicUser(string $role, bool $withTwoFactor = false): User
{
    $user = User::factory()->for(Tenant::factory()->create())->create(['role' => $role, 'password' => 'clave-correcta-123']);

    if ($withTwoFactor) {
        $user->forceFill(['two_factor_secret' => TOTP_SECRET, 'two_factor_confirmed_at' => now()])->save();
    }

    return $user;
}

it('restricts a clinic_admin without 2FA to the 2FA setup routes', function () {
    $admin = clinicUser('clinic_admin');
    $token = loginAs($admin)['token'];

    freshRequest();
    $this->withToken($token)->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('rule', 'two_factor_required');
    freshRequest();
    $this->withToken($token)->getJson('/api/v1/patients')->assertForbidden();
    freshRequest();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    freshRequest();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/setup')->assertOk();
})->group('CA-06.4', 'RF-037', 'DD-15');

it('configures TOTP, returns 10 recovery codes once and replaces the setup token with a full one', function () {
    $admin = clinicUser('clinic_admin');
    $setupToken = loginAs($admin)['token'];

    freshRequest();
    $setup = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/setup')->assertOk();
    $secret = $setup->json('secret');
    expect($setup->json('otpauth_url'))->toStartWith('otpauth://totp/')->toContain("secret={$secret}");
    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();

    freshRequest();
    $confirm = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => totp($secret)])
        ->assertOk()
        ->assertJsonPath('abilities', ['full']);

    expect($confirm->json('recovery_codes'))->toHaveCount(10)
        ->and($admin->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($admin->fresh()->two_factor_secret)->toBe($secret)
        ->and(PersonalAccessToken::findToken($setupToken))->toBeNull();
    expect(AuditLog::query()->where('action', 'auth.2fa_configured')->count())->toBe(1);

    // Los códigos se guardan solo como hash.
    $stored = DB::table('two_factor_recovery_codes')->where('user_id', $admin->id)->pluck('code_hash');
    expect($stored)->toHaveCount(10)->not->toContain($confirm->json('recovery_codes.0'));

    freshRequest();
    $this->withToken($confirm->json('token'))->getJson('/api/v1/users')->assertOk();
})->group('RF-038', 'CUS-08', 'DD-36');

it('rejects a wrong confirmation code on its field', function () {
    $token = loginAs(clinicUser('clinic_admin'))['token'];
    freshRequest();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/setup')->assertOk();

    freshRequest();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
})->group('RF-038');

it('verifies a TOTP code and issues a full token', function () {
    $dentist = clinicUser('dentist', withTwoFactor: true);
    $login = loginAs($dentist);
    expect($login['abilities'])->toBe(['2fa:pending']);

    freshRequest();
    $this->withToken($login['token'])->getJson('/api/v1/patients')->assertForbidden();

    freshRequest();
    $verified = $this->withToken($login['token'])->postJson('/api/v1/auth/2fa/verify', ['code' => totp()])
        ->assertOk()
        ->assertJsonPath('abilities', ['full'])
        ->assertJsonPath('user.id', $dentist->uuid);

    expect(PersonalAccessToken::findToken($login['token']))->toBeNull();
    freshRequest();
    $this->withToken($verified->json('token'))->getJson('/api/v1/patients')->assertOk();
})->group('RF-037', 'CUS-07');

it('accepts a recovery code only once', function () {
    // Sin 2FA el odontólogo entra con token completo y lo configura de forma voluntaria.
    $dentist = clinicUser('dentist');
    $setupToken = loginAs($dentist)['token'];
    freshRequest();
    $secret = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/setup')->json('secret');
    freshRequest();
    $codes = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => totp($secret)])->json('recovery_codes');

    freshRequest();
    $pending = loginAs($dentist)['token'];
    freshRequest();
    $this->withToken($pending)->postJson('/api/v1/auth/2fa/verify', ['recovery_code' => $codes[0]])->assertOk();

    freshRequest();
    $again = loginAs($dentist)['token'];
    freshRequest();
    $this->withToken($again)->postJson('/api/v1/auth/2fa/verify', ['recovery_code' => $codes[0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);
})->group('RF-038', 'DD-36');

it('answers 429 with Retry-After to the 6th failed code within 15 minutes and counts each failure as a login attempt', function () {
    $dentist = clinicUser('dentist', withTwoFactor: true);
    $token = loginAs($dentist)['token'];

    foreach (range(1, 5) as $attempt) {
        freshRequest();
        $this->withToken($token)->postJson('/api/v1/auth/2fa/verify', ['code' => '000000'])->assertUnprocessable();
    }

    expect($dentist->fresh()->status)->toBe('bloqueado_temporal');

    freshRequest();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/verify', ['code' => totp()])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
})->group('RF-037', 'RF-034', 'RNF-111');

it('lets a user with full access configure 2FA voluntarily', function () {
    $receptionist = clinicUser('receptionist');
    $token = loginAs($receptionist)['token'];

    freshRequest();
    $secret = $this->withToken($token)->postJson('/api/v1/auth/2fa/setup')->assertOk()->json('secret');
    freshRequest();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => totp($secret)])->assertOk();

    expect($receptionist->fresh()->two_factor_confirmed_at)->not->toBeNull();
    freshRequest();
    expect(loginAs($receptionist)['abilities'])->toBe(['2fa:pending']);
})->group('RF-038', 'CUS-08');
