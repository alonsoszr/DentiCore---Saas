<?php

/*
 * T-162 (SDD §4.1, §6.3.10; RNF-044, RNF-046): las respuestas reales de todos los endpoints
 * cumplen el documento OpenAPI 3.1 exportado, y el documento está al día con el código.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Files\FileStorage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
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
    $check($this->actingWithToken($admin)->getJson('/api/v1/auth/me'), 'GET', '/auth/me');
    $check($this->actingWithToken($superAdmin)->getJson('/api/v1/auth/me'), 'GET', '/auth/me');
    $check($this->actingWithToken($admin)->postJson('/api/v1/auth/keepalive'), 'POST', '/auth/keepalive');
    $twoFactorUser = User::factory()->for($tenant)->create(['role' => 'receptionist']);
    $check($this->actingWithToken($twoFactorUser)->postJson('/api/v1/auth/2fa/setup'), 'POST', '/auth/2fa/setup');
    $check($this->actingWithToken($twoFactorUser)->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000']), 'POST', '/auth/2fa/confirm');
    $check($this->actingWithToken($twoFactorUser)->postJson('/api/v1/auth/2fa/confirm', [
        'code' => (new Google2FA)->getCurrentOtp($twoFactorUser->fresh()->two_factor_secret),
    ]), 'POST', '/auth/2fa/confirm');
    $check($this->actingWithToken($twoFactorUser->fresh(), ['2fa:pending'])->postJson('/api/v1/auth/2fa/verify', ['code' => '000000']), 'POST', '/auth/2fa/verify');
    $check($this->actingWithToken($twoFactorUser->fresh(), ['2fa:pending'])->postJson('/api/v1/auth/2fa/verify', [
        'code' => (new Google2FA)->getCurrentOtp($twoFactorUser->fresh()->two_factor_secret),
    ]), 'POST', '/auth/2fa/verify');
    $check($this->getJson('/api/v1/public/clinics/'.$tenant->slug), 'GET', '/public/clinics/{slug}');
    $check($this->getJson('/api/v1/public/clinics/no-existe'), 'GET', '/public/clinics/{slug}');

    // Recuperación de contraseña e invitación
    $tokenFrom = fn (string $link): string => substr($link, strrpos($link, '/') + 1);
    $check($this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'clinica-contrato', 'email' => 'admin@contrato.test']), 'POST', '/auth/password/forgot');
    $check($this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'clinica-contrato']), 'POST', '/auth/password/forgot');
    $resetToken = $tokenFrom(Notification::query()->where('event', 'restablecimiento_contrasena')->latest('id')->first()->payload['links']['reset']);
    $check($this->postJson('/api/v1/auth/password/reset', ['token' => $resetToken, 'password' => 'corta', 'password_confirmation' => 'corta']), 'POST', '/auth/password/reset');
    $check($this->postJson('/api/v1/auth/password/reset', ['token' => $resetToken, 'password' => 'Contrato-Seguro-2026', 'password_confirmation' => 'Contrato-Seguro-2026']), 'POST', '/auth/password/reset');
    $check($this->postJson('/api/v1/auth/password/reset', ['token' => $resetToken, 'password' => 'Contrato-Seguro-2026', 'password_confirmation' => 'Contrato-Seguro-2026']), 'POST', '/auth/password/reset');
    $invited = User::factory()->for($tenant)->create(['role' => 'receptionist', 'password' => null]);
    app(InvitationService::class)->send($invited);
    $invitationToken = $tokenFrom(Notification::query()->where('event', 'invitacion_activacion')->latest('id')->first()->payload['links']['activate']);
    $check($this->getJson("/api/v1/auth/invitations/{$invitationToken}"), 'GET', '/auth/invitations/{token}');
    $check($this->postJson("/api/v1/auth/invitations/{$invitationToken}/accept", ['password' => 'corta', 'password_confirmation' => 'corta']), 'POST', '/auth/invitations/{token}/accept');
    $check($this->postJson("/api/v1/auth/invitations/{$invitationToken}/accept", ['password' => 'Invitado-Seguro-2026', 'password_confirmation' => 'Invitado-Seguro-2026']), 'POST', '/auth/invitations/{token}/accept');
    $check($this->getJson("/api/v1/auth/invitations/{$invitationToken}"), 'GET', '/auth/invitations/{token}');

    // Plataforma
    $check($this->actingWithToken($superAdmin)->getJson('/api/v1/platform/plans'), 'GET', '/platform/plans');
    $check($this->actingWithToken($superAdmin)->getJson('/api/v1/platform/tenants'), 'GET', '/platform/tenants');
    $check($this->actingWithToken($admin)->getJson('/api/v1/platform/tenants'), 'GET', '/platform/tenants');
    $created = $this->actingWithToken($superAdmin)->postJson('/api/v1/platform/tenants', [
        'name' => 'Clínica Nueva', 'legal_name' => 'Clínica Nueva S.A.C.', 'ruc' => '20600000013',
        'slug' => 'clinica-nueva', 'address' => 'Jr. Junín 456, Lima', 'subscription_plan' => 'basic',
        'admin' => ['name' => 'Ana', 'email' => 'ana@nueva.test'],
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($created, 'POST', '/platform/tenants');
    $check($this->actingWithToken($superAdmin)->postJson('/api/v1/platform/tenants', [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/platform/tenants');
    $newTenant = $created->json('data.id');
    $check($this->actingWithToken($superAdmin)->getJson("/api/v1/platform/tenants/{$newTenant}"), 'GET', '/platform/tenants/{tenant}');
    $check($this->actingWithToken($superAdmin)->patchJson("/api/v1/platform/tenants/{$newTenant}", ['name' => 'Clínica Renovada']), 'PATCH', '/platform/tenants/{tenant}');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/tenants/{$newTenant}/admin-invitation"), 'POST', '/platform/tenants/{tenant}/admin-invitation');
    $check($this->actingWithToken($superAdmin)->putJson("/api/v1/platform/tenants/{$newTenant}/plan", ['subscription_plan' => 'pro']), 'PUT', '/platform/tenants/{tenant}/plan');
    $check($this->actingWithToken($superAdmin)->putJson("/api/v1/platform/tenants/{$newTenant}/plan", []), 'PUT', '/platform/tenants/{tenant}/plan');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/tenants/{$newTenant}/suspend", ['reason' => 'Falta de pago']), 'POST', '/platform/tenants/{tenant}/suspend');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/tenants/{$newTenant}/suspend", ['reason' => 'Otra vez']), 'POST', '/platform/tenants/{tenant}/suspend');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/tenants/{$newTenant}/reactivate", ['reason' => 'Pago regularizado']), 'POST', '/platform/tenants/{tenant}/reactivate');

    // Usuarios
    $check($this->actingWithToken($admin)->getJson('/api/v1/users'), 'GET', '/users');
    $created = $this->actingWithToken($admin)->postJson('/api/v1/users', [
        'name' => 'Luis', 'email' => 'luis@contrato.test', 'password' => 'password123', 'role' => 'dentist', 'cop_number' => '12345',
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($created, 'POST', '/users');
    $check($this->actingWithToken($admin)->postJson('/api/v1/users', [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/users');
    $createdId = $created->json('data.id');
    $check($this->actingWithToken($admin)->getJson("/api/v1/users/{$createdId}"), 'GET', '/users/{user}');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$createdId}/invitation"), 'POST', '/users/{user}/invitation');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$createdId}/deactivate"), 'POST', '/users/{user}/deactivate');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$createdId}/reactivate"), 'POST', '/users/{user}/reactivate');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$admin->uuid}/deactivate"), 'POST', '/users/{user}/deactivate');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$admin->uuid}/invitation"), 'POST', '/users/{user}/invitation');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/users/'.$created->json('data.id'), ['name' => 'Luis R.']), 'PATCH', '/users/{user}');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/users/'.fake()->uuid(), ['name' => 'X']), 'PATCH', '/users/{user}');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/users/'.$created->json('data.id'), ['email' => 'no-es-correo']), 'PATCH', '/users/{user}');

    // Pacientes
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients'), 'GET', '/patients');
    $check($this->actingWithToken($portal)->getJson('/api/v1/patients'), 'GET', '/patients');
    $check($this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'document_id' => '70000001', 'first_name' => 'Ana', 'last_name' => 'Quispe', 'birth_date' => '1990-01-01',
        'medical_history' => ['alergias' => ['Látex']], 'user_uuid' => $portal->uuid,
    ]), 'POST', '/patients');
    $check($this->actingWithToken($admin)->postJson('/api/v1/patients', []), 'POST', '/patients');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients/'.fake()->uuid()), 'GET', '/patients/{patient}');
    $check($this->actingWithToken($portal)->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');

    // Parámetros de la clínica
    Storage::fake('s3');
    $check($this->actingWithToken($admin)->getJson('/api/v1/clinic/settings'), 'GET', '/clinic/settings');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/clinic/settings', ['budget_validity_days' => 45]), 'PATCH', '/clinic/settings');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/clinic/settings', ['budget_validity_days' => 0]), 'PATCH', '/clinic/settings');
    $check($this->actingWithToken($admin)->post('/api/v1/clinic/logo', ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json']), 'POST', '/clinic/logo');
    $check($this->actingWithToken($admin)->postJson('/api/v1/clinic/logo', []), 'POST', '/clinic/logo');

    // Archivos (URL firmada)
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
