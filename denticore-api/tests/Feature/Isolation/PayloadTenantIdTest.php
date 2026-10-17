<?php

/*
 * T-014 (SDD §6.3.1; TASK-093; RN-01, RF-001): tenant_id nunca llega desde la solicitud. Tres capas:
 * los casos completos de alta y edición, la regla estática (ningún modelo ni validación lo admite)
 * y el barrido de toda ruta de escritura de la clínica con un tenant_id ajeno.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\RouteBindings;

it('ignores a tenant_id injected in any write payload', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);

    $this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'tenant_id' => $other->id,
        'document_type' => 'dni',
        'document_number' => '70000001',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
        'birth_date' => '1990-01-01',
        'sex' => 'femenino',
        'phone' => '987654321',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $this->actingWithToken($admin)->postJson('/api/v1/users', [
        'tenant_id' => $other->id,
        'name' => 'Luis',
        'email' => 'luis@clinica.test',
        'password' => 'password123',
        'role' => 'receptionist',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $user = User::query()->where('email', 'luis@clinica.test')->sole();
    $this->actingWithToken($admin)->patchJson("/api/v1/users/{$user->uuid}", [
        'tenant_id' => $other->id,
        'name' => 'Luis Ramos',
    ])->assertOk();

    expect(TenantContext::run($tenant, fn () => Patient::query()->sole()->tenant_id))->toBe($tenant->id)
        ->and(TenantContext::run($other, fn () => Patient::query()->count()))->toBe(0)
        ->and($user->fresh()->tenant_id)->toBe($tenant->id)
        ->and($user->fresh()->name)->toBe('Luis Ramos');
})->group('T-014', 'RN-01', 'RF-001');

it('never lets a request assign tenant_id to a model or a validated payload', function () {
    // Ningún modelo admite tenant_id por asignación masiva: lo fija BelongsToTenant desde el
    // contexto de la clínica (DD-03).
    $fillable = collect(File::allFiles(app_path('Modules')))
        ->filter(fn ($file) => str_contains($file->getPath(), DIRECTORY_SEPARATOR.'Models'))
        ->map(fn ($file) => 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR)))
        ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class))
        ->filter(fn (string $class) => in_array('tenant_id', (new $class)->getFillable(), true))
        ->values()
        ->all();

    // Ninguna regla de validación de la API acepta tenant_id.
    $validated = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => preg_match("/['\"]tenant_id['\"]\s*=>\s*\[?\s*['\"](required|nullable|sometimes|integer|exists)/", $file->getContents()) === 1)
        ->map(fn ($file) => $file->getFilename())
        ->values()
        ->all();

    expect($fillable)->toBe([])->and($validated)->toBe([]);
})->group('T-014', 'RN-01', 'RF-001');

it('creates nothing in another clinic when any write route receives its tenant_id', function () {
    config(['auth.api_requests_per_minute' => 1000]);
    [$own, $other] = Tenant::factory()->count(2)->create();
    $tables = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'tenant_id'"))
        ->pluck('table_name');
    $rowsOf = fn (Tenant $tenant) => TenantContext::run($tenant, fn () => $tables
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->where('tenant_id', $tenant->id)->count()])
        ->all());
    $before = $rowsOf($other);

    // Cada ruta de escritura de la clínica, con un rol que puede usarla y un tenant_id ajeno en el cuerpo.
    $routes = collect(require base_path('tests/Authorization/matrix.php'))
        ->filter(fn (array $route) => in_array($route['method'], ['POST', 'PUT', 'PATCH'], true))
        ->filter(function (array $route): bool {
            $registered = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($candidate) => $candidate->uri() === $route['uri'] && in_array($route['method'], $candidate->methods(), true));

            return in_array('tenant', $registered?->gatherMiddleware() ?? [], true);
        });

    expect($routes->count())->toBeGreaterThan(40);

    foreach ($routes as $route) {
        // Una ruta que la matriz niega a todos los roles la autoriza la política sobre el registro
        // (p. ej., la alerta de su propio odontólogo); se prueba con el odontólogo.
        $role = collect(['clinic_admin', 'dentist', 'receptionist', 'patient'])
            ->first(fn (string $role) => ! in_array($role, $route['denied'], true)) ?? 'dentist';
        $this->actingAsRole($role, $own);
        $this->json($route['method'], RouteBindings::path($route['uri'], $own), ['tenant_id' => $other->id], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->app['auth']->forgetGuards();
    }

    expect($rowsOf($other))->toBe($before);
})->group('T-014', 'RN-01', 'RF-001', 'RNF-101');
