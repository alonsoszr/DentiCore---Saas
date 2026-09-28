<?php

/*
 * Idempotencia de escrituras críticas (TASK-009; SDD §1.7, §4.2; DD-45, RNF-079). T-026.
 */

use App\Support\Http\IdempotencyKey;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->executions = 0;

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])->post('/api/v1/_prueba/operacion', function () {
        $this->executions++;

        return response()->json(['data' => ['numero' => $this->executions]], 201);
    });

    $this->actingAsRole('receptionist');
});

it('returns the original response for a repeated Idempotency-Key within 24 hours', function () {
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/_prueba/operacion', ['monto' => '100.00'], ['Idempotency-Key' => $key]);
    $again = $this->postJson('/api/v1/_prueba/operacion', ['monto' => '100.00'], ['Idempotency-Key' => $key]);

    $first->assertCreated()->assertJsonPath('data.numero', 1);
    $again->assertCreated()->assertJsonPath('data.numero', 1)->assertHeader('Idempotent-Replayed', 'true');
    expect($this->executions)->toBe(1);

    // Pasadas 24 h la clave vence y la solicitud se procesa de nuevo.
    $this->travel(25)->hours();
    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '100.00'], ['Idempotency-Key' => $key])
        ->assertJsonPath('data.numero', 2);
})->group('DD-45', 'RNF-079');

it('responds 400 when a marked route receives no Idempotency-Key', function () {
    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '100.00'])
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->postJson('/api/v1/_prueba/operacion', [], ['Idempotency-Key' => 'no-es-uuid'])->assertStatus(400);

    expect($this->executions)->toBe(0);
})->group('DD-45');

it('responds 422 when the same key is reused with another body', function () {
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '100.00'], ['Idempotency-Key' => $key])->assertCreated();
    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '999.00'], ['Idempotency-Key' => $key])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'DD-45');

    expect($this->executions)->toBe(1);
})->group('DD-45');

it('scopes keys to each user', function () {
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '1'], ['Idempotency-Key' => $key])->assertJsonPath('data.numero', 1);

    $this->actingAsRole('receptionist');
    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '1'], ['Idempotency-Key' => $key])->assertJsonPath('data.numero', 2);
})->group('DD-45');

it('prunes expired keys', function () {
    $this->postJson('/api/v1/_prueba/operacion', ['monto' => '1'], ['Idempotency-Key' => (string) Str::uuid()]);

    $this->travel(25)->hours();
    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(IdempotencyKey::query()->count())->toBe(0);
})->group('DD-45');
