<?php

/*
 * El Súper Administrador no accede a datos de pacientes ni clínicos (TASK-032, TASK-093; SDD §3.5;
 * RN-04, RF-005). T-018: se recorren todas las rutas que AUTH_MATRIX le niega, con registros de
 * una clínica en cada parámetro; todas responden 403.
 */

use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\RouteBindings;

it('forbids super_admin on every patient and clinical route', function () {
    config(['auth.api_requests_per_minute' => 1000]);
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('super_admin');

    $routes = collect(require base_path('tests/Authorization/matrix.php'))
        ->filter(fn (array $route) => in_array('super_admin', $route['denied'], true));

    expect($routes->filter(fn (array $route) => str_contains($route['uri'], 'patients'))->count())->toBeGreaterThan(20);

    foreach ($routes as $route) {
        $path = RouteBindings::path($route['uri'], $tenant);
        $status = $this->json($route['method'], $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

        expect($status)->toBe(403, "{$route['method']} {$path} respondió {$status} al Súper Administrador");
    }
})->group('T-018', 'RN-04', 'RF-005');
