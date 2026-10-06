<?php

/*
 * Recuperación de contraseña (TASK-029; CUS-09; RF-039, RF-040, DD-15).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

function resetLinkToken(): string
{
    $link = Notification::query()->where('event', 'restablecimiento_contrasena')->latest('id')->first()->payload['links']['reset'];

    return substr($link, strrpos($link, '/') + 1);
}

function clinicReceptionist(): User
{
    $tenant = Tenant::factory()->create(['slug' => 'sonrisa']);

    return User::factory()->for($tenant)->create(['role' => 'receptionist', 'email' => 'rosa@sonrisa.test', 'password' => 'Clave-Anterior-01']);
}

it('answers the same whether the email exists or not', function () {
    clinicReceptionist();

    $existing = $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'sonrisa', 'email' => 'rosa@sonrisa.test']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'sonrisa', 'email' => 'nadie@sonrisa.test']);

    expect($existing->status())->toBe(204)->and($unknown->status())->toBe(204)
        ->and($existing->getContent())->toBe($unknown->getContent());
    expect(Notification::query()->where('event', 'restablecimiento_contrasena')->count())->toBe(1);
    expect(Notification::query()->sole()->payload['links']['reset'])->toStartWith(config('app.spa_url').'/c/sonrisa/restablecer/');
})->group('RF-039', 'CUS-09');

it('resets the password with a one-use link and revokes every session', function () {
    $user = clinicReceptionist();
    $oldToken = $user->createToken('api', ['full'])->plainTextToken;
    $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'sonrisa', 'email' => 'rosa@sonrisa.test']);
    $token = resetLinkToken();

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token, 'password' => 'Nueva-Clave-Segura-2', 'password_confirmation' => 'Nueva-Clave-Segura-2',
    ])->assertNoContent();

    expect(Hash::check('Nueva-Clave-Segura-2', $user->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::findToken($oldToken))->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.password_changed')->count())->toBe(1);

    // Un token emitido antes del restablecimiento ya no sirve.
    app('auth')->forgetGuards();
    $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    // Reutilizar el enlace responde 404.
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token, 'password' => 'Otra-Clave-Segura-3', 'password_confirmation' => 'Otra-Clave-Segura-3',
    ])->assertNotFound();
})->group('RF-039', 'RF-041');

it('expires the reset link after 60 minutes', function () {
    $this->travelTo('2026-10-05 09:00:00');
    clinicReceptionist();
    $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'sonrisa', 'email' => 'rosa@sonrisa.test']);
    $token = resetLinkToken();

    $this->travelTo('2026-10-05 10:00:01');

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token, 'password' => 'Nueva-Clave-Segura-2', 'password_confirmation' => 'Nueva-Clave-Segura-2',
    ])->assertNotFound();
})->group('RF-039');

it('applies the password policy and the history on reset', function () {
    clinicReceptionist();
    $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'sonrisa', 'email' => 'rosa@sonrisa.test']);
    $token = resetLinkToken();

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token, 'password' => 'Clave-Anterior-01', 'password_confirmation' => 'Clave-Anterior-01',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
})->group('RF-040');
