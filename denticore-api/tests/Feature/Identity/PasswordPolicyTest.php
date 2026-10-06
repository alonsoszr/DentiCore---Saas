<?php

/*
 * Política de contraseñas (TASK-029; SDD §1.7; RF-040, RNF-093, DD-15). T-024.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Identity\Services\PasswordService;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\Validator;

/** Mensajes de la política para una contraseña de un usuario. */
function policyErrors(string $password, ?User $user = null): array
{
    return Validator::make(['password' => $password], ['password' => [new PasswordPolicy($user)]])->errors()->get('password');
}

it('rejects common passwords, passwords containing the email and the last 5 passwords', function () {
    $user = User::factory()->for(Tenant::factory())->create(['name' => 'Rosa Quispe', 'email' => 'rosa.quispe@correo.test']);

    // Lista de contraseñas comunes (≥ 10 000).
    expect(policyErrors('1234567890', $user))->not->toBeEmpty()
        ->and(policyErrors('qwertyuiop', $user))->not->toBeEmpty();

    // Contiene el correo (o su parte local) o el nombre.
    expect(policyErrors('rosa.quispe-2026', $user))->not->toBeEmpty()
        ->and(policyErrors('Quispe-Segura-77', $user))->not->toBeEmpty();

    // Las 5 últimas contraseñas.
    $passwords = app(PasswordService::class);
    foreach (['Primera-Clave-01', 'Segunda-Clave-02', 'Tercera-Clave-03', 'Cuarta-Clave-04', 'Quinta-Clave-05', 'Sexta-Clave-06'] as $previous) {
        $passwords->set($user->fresh(), $previous);
    }

    expect(policyErrors('Segunda-Clave-02', $user->fresh()))->not->toBeEmpty()
        ->and(policyErrors('Sexta-Clave-06', $user->fresh()))->not->toBeEmpty()
        // La primera ya salió de las 5 últimas.
        ->and(policyErrors('Primera-Clave-01', $user->fresh()))->toBeEmpty();
})->group('RF-040', 'RNF-093');

it('requires between 10 and 128 characters', function (string $password, bool $valid) {
    expect(policyErrors($password) === [])->toBe($valid);
})->with([
    '9 caracteres' => ['Sonrisa-9', false],
    '10 caracteres' => ['Sonrisa-10', true],
    '128 caracteres' => [str_repeat('Ñandú-7', 18).'Ña', true],
    '129 caracteres' => [str_repeat('Ñandú-7', 18).'Ñan', false],
])->group('RF-040');

it('loads at least 10 000 common passwords', function () {
    expect(PasswordPolicy::commonPasswordCount())->toBeGreaterThanOrEqual(10000);
})->group('RNF-093');
