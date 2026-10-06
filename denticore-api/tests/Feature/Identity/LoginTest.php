<?php

/*
 * Inicio de sesión reforzado (TASK-027; CUS-06, SRS §11.2, SDD §1.7, §1.8; RF-032 a RF-036,
 * DD-15, DD-29). T-006, T-007 y T-008.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

function login(?string $slug, string $email, string $password = 'clave-correcta-123'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', array_filter([
        'tenant_slug' => $slug, 'email' => $email, 'password' => $password,
    ]));
}

/** Usuario con contraseña conocida en una clínica con el código indicado. */
function userIn(string $slug, string $role = 'dentist', array $attributes = []): User
{
    $tenant = Tenant::query()->where('slug', $slug)->first() ?? Tenant::factory()->create(['slug' => $slug]);

    return User::factory()->for($tenant)->create(['role' => $role, 'password' => 'clave-correcta-123', ...$attributes]);
}

it('scopes each token to its own clinic when two users share an email', function () {
    $sonrisa = userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);
    $muela = userIn('muela-sana', 'receptionist', ['email' => 'rosa@correo.test']);
    Patient::factory()->for($sonrisa->tenant)->create(['first_name' => 'Paciente', 'last_name' => 'Sonrisa']);
    Patient::factory()->for($muela->tenant)->create(['first_name' => 'Paciente', 'last_name' => 'Muela']);

    $sonrisaToken = login('sonrisa', 'rosa@correo.test')->assertOk()->json('token');
    $muelaToken = login('muela-sana', 'rosa@correo.test')->assertOk()->json('token');

    expect(PersonalAccessToken::findToken($sonrisaToken))->tokenable_id->toBe($sonrisa->id)->tenant_id->toBe($sonrisa->tenant_id)
        ->and(PersonalAccessToken::findToken($muelaToken))->tokenable_id->toBe($muela->id);

    $this->withToken($sonrisaToken)->getJson('/api/v1/patients')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Sonrisa');
    $this->app['auth']->forgetGuards();
    $this->withToken($muelaToken)->getJson('/api/v1/patients')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Muela');
})->group('CA-06.1', 'RN-01', 'RN-05');

it('locks the user for 15 minutes after 5 consecutive failures', function () {
    $user = userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);
    $this->travelTo('2026-10-05 09:00:00');

    foreach (range(1, 5) as $attempt) {
        login('sonrisa', 'rosa@correo.test', 'clave-equivocada')->assertUnauthorized();
    }

    expect($user->fresh())
        ->status->toBe('bloqueado_temporal')
        ->and($user->fresh()->locked_until->toDateTimeString())->toBe('2026-10-05 09:15:00');
    expect(Notification::query()->where('event', 'cuenta_bloqueada')->where('recipient_user_id', $user->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'auth.locked')->count())->toBe(1);

    // CA-06.2: el sexto intento con la contraseña correcta dentro de los 15 min se rechaza igual.
    $this->travelTo('2026-10-05 09:14:00');
    login('sonrisa', 'rosa@correo.test')->assertUnauthorized()->assertJsonPath('detail', 'Credenciales inválidas.');

    // Pasados los 15 min puede ingresar y el contador vuelve a cero.
    $this->travelTo('2026-10-05 09:15:01');
    login('sonrisa', 'rosa@correo.test')->assertOk();
    expect($user->fresh())->status->toBe('activo')->failed_login_count->toBe(0);
})->group('CA-06.2', 'RF-034');

it('returns the same status and message for an unknown email and a wrong password', function () {
    userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);
    userIn('sonrisa', 'receptionist', ['email' => 'inactiva@correo.test', 'is_active' => false]);

    $unknown = login('sonrisa', 'nadie@correo.test', 'clave-equivocada');
    $wrongPassword = login('sonrisa', 'rosa@correo.test', 'clave-equivocada');
    $inactive = login('sonrisa', 'inactiva@correo.test');
    $unknownClinic = login('no-existe', 'rosa@correo.test');

    foreach ([$unknown, $wrongPassword, $inactive, $unknownClinic] as $response) {
        $response->assertUnauthorized();
        expect($response->json('detail'))->toBe('Credenciales inválidas.')
            ->and($response->json('title'))->toBe($unknown->json('title'));
    }
})->group('CA-06.3', 'RF-033');

it('issues the token ability by the second factor state and role', function (string $role, bool $confirmed, array $abilities, bool $pending, bool $setup) {
    $user = $role === 'super_admin'
        ? User::factory()->superAdmin()->create(['email' => 'sa@denticore.test', 'password' => 'clave-correcta-123'])
        : userIn('sonrisa', $role, ['email' => 'usuario@correo.test']);
    if ($confirmed) {
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    }
    $this->travelTo('2026-10-05 09:00:00');

    $response = login($role === 'super_admin' ? null : 'sonrisa', $user->email)->assertOk();

    expect($response->json('abilities'))->toBe($abilities)
        ->and($response->json('requires_2fa'))->toBe($pending)
        ->and($response->json('requires_2fa_setup'))->toBe($setup);
    expect(PersonalAccessToken::findToken($response->json('token'))->expires_at->toDateTimeString())->toBe('2026-10-05 21:00:00');

    // Solo el token completo devuelve el perfil (SDD §1.8).
    expect($response->json('user.id'))->toBe($abilities === ['full'] ? $user->uuid : null);
})->with([
    'odontólogo sin 2FA' => ['dentist', false, ['full'], false, false],
    'odontólogo con 2FA' => ['dentist', true, ['2fa:pending'], true, false],
    'administrador de clínica sin 2FA' => ['clinic_admin', false, ['2fa:setup'], false, true],
    'administrador de clínica con 2FA' => ['clinic_admin', true, ['2fa:pending'], true, false],
    'súper administrador sin 2FA' => ['super_admin', false, ['2fa:setup'], false, true],
])->group('RF-036', 'RF-037', 'DD-15');

it('stores the clinic, IP and user agent of the session and keeps at most 5 full tokens', function () {
    $user = userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);

    foreach (range(1, 6) as $session) {
        // Un minuto entre sesiones para no chocar con throttle:login (5/min por IP).
        $this->travel(61)->seconds();
        $this->withHeader('User-Agent', "Navegador {$session}")->postJson('/api/v1/auth/login', [
            'tenant_slug' => 'sonrisa', 'email' => 'rosa@correo.test', 'password' => 'clave-correcta-123',
        ])->assertOk();
    }

    $tokens = PersonalAccessToken::query()->where('tokenable_id', $user->id)->orderBy('id')->get();
    expect($tokens)->toHaveCount(5)
        ->and($tokens->first()->user_agent)->toBe('Navegador 2')
        ->and($tokens->first()->tenant_id)->toBe($user->tenant_id)
        ->and($tokens->first()->ip_address)->toBe('127.0.0.1');
})->group('RNF-115', 'RF-050');

it('lets only the clinic_admin of a cancelled clinic in', function () {
    $admin = userIn('cerrada', 'clinic_admin', ['email' => 'admin@cerrada.test']);
    userIn('cerrada', 'receptionist', ['email' => 'rosa@cerrada.test']);
    $admin->tenant->forceFill(['status' => 'cancelada'])->save();

    login('cerrada', 'admin@cerrada.test')->assertOk();
    login('cerrada', 'rosa@cerrada.test')->assertUnauthorized()->assertJsonPath('detail', 'Credenciales inválidas.');
})->group('RN-07', 'CUS-06');

it('logs a user of a suspended clinic in', function () {
    $user = userIn('pausada', 'dentist', ['email' => 'diego@pausada.test']);
    $user->tenant->forceFill(['status' => 'suspendida'])->save();

    login('pausada', 'diego@pausada.test')->assertOk();
})->group('RN-07', 'CUS-06');

it('rejects a pending user without password', function () {
    userIn('sonrisa', 'receptionist', ['email' => 'nueva@correo.test', 'password' => null]);

    login('sonrisa', 'nueva@correo.test')->assertUnauthorized();
})->group('DD-22', 'RF-033');

it('limits login attempts to 5 per minute per IP', function () {
    userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);

    foreach (range(1, 5) as $attempt) {
        login('sonrisa', "otro{$attempt}@correo.test", 'x')->assertUnauthorized();
    }

    login('sonrisa', 'rosa@correo.test')->assertStatus(429)->assertHeader('Retry-After');
})->group('RF-035');

it('audits successful and failed logins and logouts', function () {
    $user = userIn('sonrisa', 'receptionist', ['email' => 'rosa@correo.test']);

    login('sonrisa', 'rosa@correo.test', 'clave-equivocada');
    $token = login('sonrisa', 'rosa@correo.test')->json('token');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['auth.login_failed', 'auth.login_ok', 'auth.logout']);
    expect(AuditLog::query()->where('action', 'auth.logout')->sole()->tenant_id)->toBe($user->tenant_id);
})->group('RN-67', 'CUS-10');

it('serves the public profile of a clinic by its access code', function () {
    Tenant::factory()->create(['name' => 'Clínica Sonrisa', 'slug' => 'sonrisa']);

    $this->getJson('/api/v1/public/clinics/sonrisa')
        ->assertOk()
        ->assertJsonPath('data.name', 'Clínica Sonrisa')
        ->assertJsonPath('data.slug', 'sonrisa')
        ->assertJsonMissingPath('data.ruc');

    $this->getJson('/api/v1/public/clinics/no-existe')->assertNotFound();
})->group('RF-032', 'DD-29');
