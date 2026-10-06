<?php

/*
 * T-162 (SDD §4.1, §6.3.10; RNF-044, RNF-046): las respuestas reales de todos los endpoints
 * cumplen el documento OpenAPI 3.1 exportado, y el documento está al día con el código.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Files\FileStorage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\OpenApiContract;

it('keeps openapi.json in sync with the code', function () {
    Artisan::call('scramble:export', ['--path' => $fresh = storage_path('framework/testing/openapi-actual.json')]);

    $committed = json_decode((string) file_get_contents(OpenApiContract::path()), true);
    $generated = json_decode((string) file_get_contents($fresh), true);
    @unlink($fresh);

    expect($generated)->toBe($committed, 'openapi.json está desactualizado: ejecute php artisan scramble:export');
})->group('RNF-044');

it('matches every API response against the OpenAPI 3.1 document', function () {
    OpenApiContract::$covered = [];
    $check = fn ($response, string $method, string $path) => OpenApiContract::assertMatches($response, $method, $path);

    $superAdmin = User::factory()->superAdmin()->create(['email' => 'sa@denticore.test']);
    $tenant = Tenant::factory()->create(['slug' => 'clinica-contrato']);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'email' => 'admin@contrato.test']);
    $portal = User::factory()->for($tenant)->create(['role' => 'patient']);
    $patient = Patient::factory()->for($tenant)->create([
        'medical_history' => ['alergias' => ['Penicilina'], 'enfermedades' => [], 'medicamentos' => [], 'observaciones' => null],
    ]);

    // Autenticación
    $check($this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-contrato', 'email' => 'admin@contrato.test', 'password' => 'password']), 'POST', '/auth/login');
    $check($this->postJson('/api/v1/auth/login', []), 'POST', '/auth/login');
    $check($this->getJson('/api/v1/auth/me'), 'GET', '/auth/me');
    $check($this->actingAs($admin, 'sanctum')->getJson('/api/v1/auth/me'), 'GET', '/auth/me');
    $check($this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/auth/me'), 'GET', '/auth/me');

    // Plataforma
    $check($this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/platform/plans'), 'GET', '/platform/plans');
    $check($this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/platform/tenants'), 'GET', '/platform/tenants');
    $check($this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/tenants'), 'GET', '/platform/tenants');
    $created = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/platform/tenants', [
        'name' => 'Clínica Nueva', 'legal_name' => 'Clínica Nueva S.A.C.', 'ruc' => '20600000013',
        'slug' => 'clinica-nueva', 'address' => 'Jr. Junín 456, Lima', 'subscription_plan' => 'basic',
        'admin' => ['name' => 'Ana', 'email' => 'ana@nueva.test'],
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($created, 'POST', '/platform/tenants');
    $check($this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/platform/tenants', [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/platform/tenants');
    $newTenant = $created->json('data.id');
    $check($this->actingAs($superAdmin, 'sanctum')->getJson("/api/v1/platform/tenants/{$newTenant}"), 'GET', '/platform/tenants/{tenant}');
    $check($this->actingAs($superAdmin, 'sanctum')->patchJson("/api/v1/platform/tenants/{$newTenant}", ['name' => 'Clínica Renovada']), 'PATCH', '/platform/tenants/{tenant}');
    $check($this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/platform/tenants/{$newTenant}/admin-invitation"), 'POST', '/platform/tenants/{tenant}/admin-invitation');

    // Usuarios
    $check($this->actingAs($admin, 'sanctum')->getJson('/api/v1/users'), 'GET', '/users');
    $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Luis', 'email' => 'luis@contrato.test', 'password' => 'password123', 'role' => 'dentist', 'cop_number' => '12345',
    ]);
    $check($created, 'POST', '/users');
    $check($this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', []), 'POST', '/users');
    $check($this->actingAs($admin, 'sanctum')->patchJson('/api/v1/users/'.$created->json('data.id'), ['name' => 'Luis R.']), 'PATCH', '/users/{user}');
    $check($this->actingAs($admin, 'sanctum')->patchJson('/api/v1/users/'.fake()->uuid(), ['name' => 'X']), 'PATCH', '/users/{user}');
    $check($this->actingAs($admin, 'sanctum')->patchJson('/api/v1/users/'.$created->json('data.id'), ['email' => 'no-es-correo']), 'PATCH', '/users/{user}');

    // Pacientes
    $check($this->actingAs($admin, 'sanctum')->getJson('/api/v1/patients'), 'GET', '/patients');
    $check($this->actingAs($portal, 'sanctum')->getJson('/api/v1/patients'), 'GET', '/patients');
    $check($this->actingAs($admin, 'sanctum')->postJson('/api/v1/patients', [
        'document_id' => '70000001', 'first_name' => 'Ana', 'last_name' => 'Quispe', 'birth_date' => '1990-01-01',
        'medical_history' => ['alergias' => ['Látex']], 'user_uuid' => $portal->uuid,
    ]), 'POST', '/patients');
    $check($this->actingAs($admin, 'sanctum')->postJson('/api/v1/patients', []), 'POST', '/patients');
    $check($this->actingAs($admin, 'sanctum')->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');
    $check($this->actingAs($admin, 'sanctum')->getJson('/api/v1/patients/'.fake()->uuid()), 'GET', '/patients/{patient}');
    $check($this->actingAs($portal, 'sanctum')->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');

    // Archivos (URL firmada)
    Storage::fake('s3');
    $url = TenantContext::run($tenant, function () {
        $file = app(FileStorage::class)->storeGenerated('%PDF-1.4', 'reporte.pdf', 'application/pdf');

        return app(FileStorage::class)->temporaryUrl($file);
    });
    $check($this->get($url), 'GET', '/files/{tenant}/{file}');
    $check($this->getJson($url.'x'), 'GET', '/files/{tenant}/{file}');

    // Cierre de sesión
    $token = $admin->createToken('api')->plainTextToken;
    app('auth')->forgetGuards();
    $check($this->withToken($token)->postJson('/api/v1/auth/logout'), 'POST', '/auth/logout');

    // Toda operación documentada se ejercitó al menos una vez.
    $documented = collect((array) OpenApiContract::document()->paths)
        ->flatMap(fn ($operations, $path) => collect(array_keys((array) $operations))->map(fn ($method) => strtoupper($method).' '.$path))
        ->sort()->values()->all();

    expect(collect(OpenApiContract::$covered)->unique()->sort()->values()->all())->toBe($documented);
})->group('RNF-044', 'RNF-046');
