<?php

/*
 * T-019 (SDD §3.8, §6.3.1; RN-06, RF-004): cada celda ❌ de AUTH_MATRIX recibe 403 (o 404
 * cuando el rol no puede ver el registro), y ninguna ruta queda fuera de la matriz.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * @return list<array{method: string, uri: string, cus: string, denied: list<string>}>
 */
function authMatrix(): array
{
    return require __DIR__.'/matrix.php';
}

dataset('forbidden cells', function () {
    foreach (authMatrix() as $route) {
        foreach ($route['denied'] as $role) {
            yield "{$route['cus']} {$route['method']} {$route['uri']} as {$role}" => [$route['method'], $route['uri'], $role];
        }
    }
});

it('denies every forbidden cell of the Must use cases', function (string $method, string $uri, string $role) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole($role, $tenant);

    // Parámetros de ruta de la propia clínica del usuario (o de una clínica cualquiera para SA).
    $bindings = [
        '{tenant}' => fn () => $tenant->uuid,
        '{user}' => fn () => User::factory()->for($tenant)->create(['role' => 'dentist'])->uuid,
        '{patient}' => fn () => Patient::factory()->for($tenant)->create()->uuid,
        // Representación inexistente: basta con un uuid; el rol se rechaza antes de buscarla.
        '{representative}' => fn () => (string) Str::uuid(),
    ];

    $path = preg_replace_callback('/\{[a-z_]+\}/', fn ($match) => $bindings[$match[0]](), $uri);

    $status = $this->json($method, '/'.$path)->status();

    expect($status)->toBeIn([403, 404], "{$method} /{$path} como {$role} respondió {$status}");
})->with('forbidden cells')->group('RN-06', 'RF-004');

it('lists every API route in AUTH_MATRIX', function () {
    $inMatrix = collect(authMatrix())->map(fn ($route) => "{$route['method']} {$route['uri']}");

    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => "{$method} {$route->uri()}"));

    expect($registered->diff($inMatrix)->values()->all())->toBe([])
        ->and($inMatrix->diff($registered)->values()->all())->toBe([]);
})->group('RF-004');
