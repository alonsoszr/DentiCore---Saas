<?php

/*
 * Vencimiento de tokens por inactividad y absoluto, renovación y cierre (TASK-027; SDD §1.7;
 * RF-036, RF-041, DD-15). T-010.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/** Inicia sesión por la API y devuelve el token (completo, sin 2FA). */
function sessionToken(string $role): string
{
    $tenant = Tenant::factory()->create(['slug' => 'sonrisa-'.uniqid()]);
    $user = User::factory()->for($tenant)->create(['role' => $role, 'password' => 'clave-correcta-123']);

    return test()->postJson('/api/v1/auth/login', [
        'tenant_slug' => $tenant->slug, 'email' => $user->email, 'password' => 'clave-correcta-123',
    ])->assertOk()->json('token');
}

/** Llama a /auth/me con el token, como una solicitud nueva. */
function meWith(string $token): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/auth/me');
}

it('expires a staff token after 30 minutes and a portal token after 15 minutes of inactivity', function (string $role, int $minutes) {
    $this->travelTo('2026-10-05 09:00:00');
    $token = sessionToken($role);

    // Un minuto antes del límite sigue vigente (y esta llamada renueva la actividad).
    $this->travel($minutes - 1)->minutes();
    meWith($token)->assertOk();

    // Sin uso durante el límite completo desde la última actividad: 401.
    $this->travel($minutes)->minutes();
    $this->travel(1)->seconds();
    meWith($token)->assertUnauthorized();

    expect(PersonalAccessToken::findToken($token))->toBeNull();
})->with([
    'personal' => ['receptionist', 30],
    'portal' => ['patient', 15],
])->group('CA-06.5', 'RF-036');

it('keeps the session alive with keepalive', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $token = sessionToken('dentist');

    $this->travelTo('2026-10-05 09:25:00');
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/keepalive')->assertNoContent();

    $this->travelTo('2026-10-05 09:50:00');
    meWith($token)->assertOk();
})->group('RF-036');

it('expires every token 12 hours after it was issued', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $token = sessionToken('dentist');

    foreach (range(1, 23) as $halfHour) {
        $this->travel(29)->minutes();
        meWith($token)->assertOk();
    }

    $this->travelTo('2026-10-05 21:00:01');
    meWith($token)->assertUnauthorized();
})->group('RF-036');

it('rejects the token of a user who is no longer active', function () {
    $token = sessionToken('dentist');
    PersonalAccessToken::findToken($token)->tokenable->forceFill(['status' => 'inactivo'])->save();

    meWith($token)->assertUnauthorized();
})->group('RF-044', 'RF-036');

it('revokes the token on logout', function () {
    $token = sessionToken('dentist');

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(PersonalAccessToken::findToken($token))->toBeNull();
    meWith($token)->assertUnauthorized();
})->group('RF-041', 'CUS-10');
