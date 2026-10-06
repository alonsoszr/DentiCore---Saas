<?php

/*
 * Activación de cuenta por invitación (TASK-029; CUS-01 FA-2, CUS-11; RF-016, RF-040, DD-22).
 * T-005.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Tokens\OneTimeToken;
use Illuminate\Support\Facades\Hash;

/** Invita a un usuario pendiente y devuelve el token en claro del enlace. */
function invite(User $user): string
{
    app(InvitationService::class)->send($user);
    $link = Notification::query()->latest('id')->first()->payload['links']['activate'];

    return substr($link, strrpos($link, '/') + 1);
}

function pendingUser(string $role = 'clinic_admin'): User
{
    $tenant = Tenant::factory()->create(['name' => 'Clínica Sonrisa', 'slug' => 'sonrisa']);

    return User::factory()->for($tenant)->create([
        'role' => $role, 'name' => 'Ana Quispe', 'email' => 'ana@sonrisa.test', 'password' => null,
    ]);
}

it('shows the invitation data for the activation screen', function () {
    $user = pendingUser();
    $token = invite($user);

    $this->getJson("/api/v1/auth/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Quispe')
        ->assertJsonPath('data.email', 'ana@sonrisa.test')
        ->assertJsonPath('data.role', 'clinic_admin')
        ->assertJsonPath('data.clinic.name', 'Clínica Sonrisa')
        ->assertJsonPath('data.clinic.slug', 'sonrisa');
})->group('RF-016', 'CUS-01');

it('activates the account with a valid password and consumes the link', function () {
    $user = pendingUser();
    $token = invite($user);

    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'password' => 'Sonrisa-Segura-2026', 'password_confirmation' => 'Sonrisa-Segura-2026',
    ])->assertNoContent();

    expect($user->fresh())
        ->status->toBe('activo')
        ->password_changed_at->not->toBeNull()
        ->and(Hash::check('Sonrisa-Segura-2026', $user->fresh()->password))->toBeTrue();

    // Un solo uso: el mismo enlace ya no sirve (misma respuesta que uno inexistente).
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'password' => 'Otra-Clave-Segura-1', 'password_confirmation' => 'Otra-Clave-Segura-1',
    ])->assertNotFound();
})->group('RF-016', 'DD-22');

it('rejects an activation link older than 72 hours', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $user = pendingUser();
    $token = invite($user);

    $this->travelTo('2026-10-08 09:00:01');

    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'password' => 'Sonrisa-Segura-2026', 'password_confirmation' => 'Sonrisa-Segura-2026',
    ])->assertNotFound();

    expect($user->fresh())->status->toBe('pendiente_activacion')->password->toBeNull();
})->group('CA-01.5', 'RF-016');

it('applies the password policy when activating', function () {
    $user = pendingUser();
    $token = invite($user);

    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'password' => 'ana@sonrisa.test-2026', 'password_confirmation' => 'ana@sonrisa.test-2026',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    expect(OneTimeToken::query()->whereNull('used_at')->count())->toBe(1);
})->group('RF-040');
