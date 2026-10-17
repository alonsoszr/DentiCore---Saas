<?php

/*
 * T-019 (SDD §3.8, §6.3.1; RN-06, RF-004): cada celda ❌ de AUTH_MATRIX recibe 403 (o 404
 * cuando el rol no puede ver el registro), y ninguna ruta queda fuera de la matriz.
 */

use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Tests\Support\PortalFixtures;
use Tests\Support\RouteBindings;

/**
 * @return list<array{method: string, uri: string, cus: string, denied: list<string>}>
 */
function authMatrix(): array
{
    return require __DIR__.'/matrix.php';
}

dataset('forbidden cells', function () {
    foreach (authMatrix() as $route) {
        // REP (representante, SDD §3.4): las mismas capacidades que PA, limitadas a sus representados.
        $denied = in_array('patient', $route['denied'], true) ? [...$route['denied'], 'representative'] : $route['denied'];

        foreach ($denied as $role) {
            yield "{$route['cus']} {$route['method']} {$route['uri']} as {$role}" => [$route['method'], $route['uri'], $role];
        }
    }
});

it('denies every forbidden cell of the Must use cases', function (string $method, string $uri, string $role) {
    $tenant = Tenant::factory()->create();
    $role === 'representative'
        ? $this->actingWithToken(PortalFixtures::representative($tenant, PortalFixtures::minor($tenant)))
        : $this->actingAsRole($role, $tenant);

    // Parámetros de ruta de la propia clínica del usuario (o de una clínica cualquiera para SA).
    $path = RouteBindings::path($uri, $tenant);

    $status = $this->json($method, $path)->status();

    expect($status)->toBeIn([403, 404], "{$method} {$path} como {$role} respondió {$status}");
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

it('covers the use cases of MS-01 in AUTH_MATRIX', function () {
    $covered = collect(authMatrix())->pluck('cus')->unique();

    foreach (['CUS-01', 'CUS-02', 'CUS-03', 'CUS-04', 'CUS-06', 'CUS-07', 'CUS-08', 'CUS-09', 'CUS-10', 'CUS-11',
        'CUS-13', 'CUS-14', 'CUS-15', 'CUS-16', 'CUS-17'] as $cus) {
        expect($covered)->toContain($cus);
    }
})->group('T-019', 'RN-06', 'RF-004');

it('covers the use cases of MS-02 in AUTH_MATRIX', function () {
    $covered = collect(authMatrix())->pluck('cus')->unique();

    // CUS-27 es un proceso programado sin ruta (attentions:auto-close).
    foreach (['CUS-21', 'CUS-22', 'CUS-23', 'CUS-24', 'CUS-25', 'CUS-26', 'CUS-80', 'CUS-81'] as $cus) {
        expect($covered)->toContain($cus);
    }
})->group('T-019', 'RN-06', 'RF-004');

it('covers the use cases of MS-03 in AUTH_MATRIX', function () {
    $covered = collect(authMatrix())->pluck('cus')->unique();

    // CUS-38 es un proceso programado sin ruta (budgets:expire).
    foreach (['CUS-32', 'CUS-33', 'CUS-34', 'CUS-35', 'CUS-36', 'CUS-37', 'CUS-39', 'CUS-40', 'CUS-82', 'CUS-83'] as $cus) {
        expect($covered)->toContain($cus);
    }
})->group('T-019', 'RN-06', 'RF-004');

it('covers the use cases of MS-05 in AUTH_MATRIX', function () {
    $covered = collect(authMatrix())->pluck('cus')->unique();

    // CUS-57 (alerta de riesgo alto) lo ejecuta el sistema dentro de CUS-55, sin ruta propia.
    foreach (['CUS-54', 'CUS-55', 'CUS-56', 'CUS-58'] as $cus) {
        expect($covered)->toContain($cus);
    }
})->group('T-019', 'RN-06', 'RF-004');

it('covers the use cases of MS-04 in AUTH_MATRIX', function () {
    $covered = collect(authMatrix())->pluck('cus')->unique();

    // CUS-51 y CUS-52 son procesos programados sin ruta (appointments:mark-no-shows y
    // appointments:send-reminders).
    foreach (['CUS-44', 'CUS-45', 'CUS-46', 'CUS-47', 'CUS-48', 'CUS-49', 'CUS-50', 'CUS-53', 'CUS-77'] as $cus) {
        expect($covered)->toContain($cus);
    }
})->group('T-019', 'RN-06', 'RF-004');

it('covers the portal use cases of MS-06 in AUTH_MATRIX and opens them only to PA and REP', function () {
    $portal = collect(authMatrix())->filter(fn ($route) => str_starts_with($route['uri'], 'api/v1/portal/'));

    foreach (['CUS-17', 'CUS-24', 'CUS-36', 'CUS-37', 'CUS-46', 'CUS-47', 'CUS-48', 'CUS-49', 'CUS-77'] as $cus) {
        expect($portal->pluck('cus'))->toContain($cus);
    }

    foreach ($portal as $route) {
        expect($route['denied'])->toEqualCanonicalizing(['super_admin', 'clinic_admin', 'dentist', 'receptionist']);
    }
})->group('T-019', 'RN-06', 'RF-004');
