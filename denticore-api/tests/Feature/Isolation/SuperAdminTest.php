<?php

/*
 * El Súper Administrador no accede a datos de pacientes ni clínicos (TASK-032; SDD §3.5; RN-04,
 * RF-005). T-018: se recorren todas las rutas registradas bajo /patients.
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('forbids super_admin on every patient and clinical route', function () {
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create();
    $this->actingAsRole('super_admin');

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/patients'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [$m, $route->uri()]));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        $path = '/'.preg_replace_callback('/\{[a-z_]+\}/', fn ($match) => match ($match[0]) {
            '{patient}' => $patient->uuid,
            '{tooth}' => '16',
            default => (string) Str::uuid(),
        }, $uri);
        $status = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

        expect($status)->toBe(403, "{$method} {$path} respondió {$status} al Súper Administrador");
    }
})->group('RN-04', 'RF-005');
