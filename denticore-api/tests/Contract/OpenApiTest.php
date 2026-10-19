<?php

/*
 * T-162 (SDD §4.1, §6.3.10; RNF-044, RNF-046): las respuestas reales de todos los endpoints
 * cumplen el documento OpenAPI 3.1 exportado, y el documento está al día con el código.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\ModelVersion;
use App\Modules\Risk\Services\CircuitBreaker;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Services\NotificationService;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Files\FileStorage;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ArcoFixtures;
use Tests\Support\ClinicalFixtures;
use Tests\Support\MlEngine;
use Tests\Support\OpenApiContract;
use Tests\Support\PortalFixtures;
use Tests\Support\RiskFixtures;

it('keeps openapi.json in sync with the code', function () {
    Artisan::call('scramble:export', ['--path' => $fresh = storage_path('framework/testing/openapi-actual.json')]);

    $committed = json_decode((string) file_get_contents(OpenApiContract::path()), true);
    $generated = json_decode((string) file_get_contents($fresh), true);
    @unlink($fresh);

    expect($generated)->toBe($committed, 'openapi.json está desactualizado: ejecute php artisan scramble:export');
})->group('RNF-044');

it('matches every API response against the OpenAPI 3.1 document', function () {
    OpenApiContract::$covered = [];
    // El recorrido hace más de 60 solicitudes por usuario (throttle:api); el límite real se prueba aparte.
    config(['auth.api_requests_per_minute' => 1000]);
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

    // Perfil y segundo factor propios (CUS-78)
    $totp = fn () => (new Google2FA)->getCurrentOtp($twoFactorUser->fresh()->two_factor_secret);
    $check($this->actingWithToken($twoFactorUser->fresh())->postJson('/api/v1/auth/2fa/recovery-codes', ['code' => '000000']), 'POST', '/auth/2fa/recovery-codes');
    $check($this->actingWithToken($twoFactorUser->fresh())->postJson('/api/v1/auth/2fa/recovery-codes', ['code' => $totp()]), 'POST', '/auth/2fa/recovery-codes');
    $check($this->actingWithToken($admin)->deleteJson('/api/v1/auth/2fa', ['code' => '000000']), 'DELETE', '/auth/2fa');
    $check($this->actingWithToken($twoFactorUser->fresh())->deleteJson('/api/v1/auth/2fa', ['code' => '000000']), 'DELETE', '/auth/2fa');
    $check($this->actingWithToken($twoFactorUser->fresh())->deleteJson('/api/v1/auth/2fa', ['code' => $totp()]), 'DELETE', '/auth/2fa');
    $profileUser = User::factory()->for($tenant)->create(['role' => 'dentist', 'name' => 'Diego Quispe', 'password' => 'Clave-Segura-2026']);
    $check($this->actingWithToken($profileUser)->patchJson('/api/v1/me/profile', ['name' => 'Diego Quispe Huamán']), 'PATCH', '/me/profile');
    $check($this->actingWithToken($profileUser)->patchJson('/api/v1/me/profile', ['name' => '']), 'PATCH', '/me/profile');
    $check($this->actingWithToken($profileUser)->putJson('/api/v1/me/password', ['current_password' => 'otra', 'password' => 'Nueva-Clave-Larga-2026', 'password_confirmation' => 'Nueva-Clave-Larga-2026']), 'PUT', '/me/password');
    $check($this->actingWithToken($profileUser)->putJson('/api/v1/me/password', ['current_password' => 'Clave-Segura-2026', 'password' => 'Nueva-Clave-Larga-2026', 'password_confirmation' => 'Nueva-Clave-Larga-2026']), 'PUT', '/me/password');

    // Restablecer el acceso (CUS-79)
    $lockedUser = User::factory()->for($tenant)->create(['role' => 'receptionist', 'status' => 'bloqueado_temporal', 'locked_until' => now()->addMinutes(15)]);
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$lockedUser->uuid}/unlock"), 'POST', '/users/{user}/unlock');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$lockedUser->uuid}/unlock"), 'POST', '/users/{user}/unlock');
    $check($this->actingWithToken($admin)->postJson("/api/v1/users/{$lockedUser->uuid}/2fa/reset"), 'POST', '/users/{user}/2fa/reset');
    $check($this->actingWithToken($admin)->postJson('/api/v1/users/'.fake()->uuid().'/2fa/reset'), 'POST', '/users/{user}/2fa/reset');
    $lockedAdmin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'status' => 'bloqueado_temporal', 'locked_until' => now()->addMinutes(15)]);
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/clinic-admins/{$lockedAdmin->uuid}/unlock"), 'POST', '/platform/clinic-admins/{user}/unlock');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/clinic-admins/{$lockedUser->uuid}/unlock"), 'POST', '/platform/clinic-admins/{user}/unlock');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/clinic-admins/{$lockedUser->uuid}/2fa/reset"), 'POST', '/platform/clinic-admins/{user}/2fa/reset');
    $check($this->actingWithToken($superAdmin)->postJson("/api/v1/platform/clinic-admins/{$lockedAdmin->uuid}/2fa/reset"), 'POST', '/platform/clinic-admins/{user}/2fa/reset');

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
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients?q=ana&per_page=5'), 'GET', '/patients');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients?per_page=101'), 'GET', '/patients');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients/lookup?document_type=dni&document_number=99999999'), 'GET', '/patients/lookup');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients/lookup?document_type=dni&document_number='.$patient->document_number), 'GET', '/patients/lookup');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients/lookup'), 'GET', '/patients/lookup');
    $check($this->actingWithToken($portal)->getJson('/api/v1/patients'), 'GET', '/patients');
    $registered = $this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '70000001', 'first_name' => 'Ana', 'last_name' => 'Quispe',
        'birth_date' => '1990-01-01', 'sex' => 'femenino', 'phone' => '987654321', 'address' => 'Jr. Junín 456, Lima',
        'user_uuid' => $portal->uuid,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($registered, 'POST', '/patients');
    $check($this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '70000001', 'first_name' => 'Otra', 'last_name' => 'Ficha',
        'birth_date' => '1990-01-01', 'sex' => 'femenino', 'phone' => '987654321',
    ], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients');
    $check($this->actingWithToken($admin)->postJson('/api/v1/patients', [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients');
    $registeredId = $registered->json('data.id');
    $check($this->actingWithToken($admin)->patchJson("/api/v1/patients/{$registeredId}", ['phone' => '911222333']), 'PATCH', '/patients/{patient}');
    $check($this->actingWithToken($admin)->patchJson("/api/v1/patients/{$registeredId}", ['phone' => '014567890']), 'PATCH', '/patients/{patient}');
    $check($this->actingWithToken($admin)->patchJson("/api/v1/patients/{$registeredId}", ['document_type' => 'dni', 'document_number' => (string) $patient->document_number]), 'PATCH', '/patients/{patient}');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/patients/'.fake()->uuid()), 'GET', '/patients/{patient}');
    $check($this->actingWithToken($portal)->getJson("/api/v1/patients/{$patient->uuid}"), 'GET', '/patients/{patient}');

    // Representantes legales
    $representatives = "/api/v1/patients/{$patient->uuid}/representatives";
    $created = $this->actingWithToken($admin)->postJson($representatives, [
        'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
        'relationship' => 'madre', 'phone' => '912345678', 'email' => 'rosa@correo.test', 'valid_from' => '2026-10-05',
    ]);
    $check($created, 'POST', '/patients/{patient}/representatives');
    $check($this->actingWithToken($admin)->postJson($representatives, []), 'POST', '/patients/{patient}/representatives');
    $check($this->actingWithToken($admin)->getJson($representatives), 'GET', '/patients/{patient}/representatives');
    $representativeId = $created->json('data.id');
    $check($this->actingWithToken($admin)->postJson("{$representatives}/{$representativeId}/end", ['reason' => 'x']), 'POST', '/patients/{patient}/representatives/{representative}/end');
    $check($this->actingWithToken($admin)->postJson("{$representatives}/{$representativeId}/end", ['reason' => 'revocada']), 'POST', '/patients/{patient}/representatives/{representative}/end');
    $check($this->actingWithToken($admin)->postJson("{$representatives}/{$representativeId}/end", ['reason' => 'revocada']), 'POST', '/patients/{patient}/representatives/{representative}/end');

    // Consentimiento de datos
    $adult = Patient::factory()->for($tenant)->create(['document_number' => '45678912', 'birth_date' => '1990-01-31']);
    $unrepresentedMinor = Patient::factory()->for($tenant)->create(['birth_date' => now()->subYears(10)->toDateString()]);
    $consents = "/api/v1/patients/{$adult->uuid}/consents";
    $check($this->actingWithToken($admin)->getJson("{$consents}/preview"), 'GET', '/patients/{patient}/consents/preview');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$unrepresentedMinor->uuid}/consents/preview"), 'GET', '/patients/{patient}/consents/preview');
    $granted = $this->actingWithToken($admin)->postJson($consents, [
        'channel' => 'presencial', 'purpose_care' => true, 'purpose_notifications' => true, 'confirmation_document_number' => '45678912',
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($granted, 'POST', '/patients/{patient}/consents');
    $check($this->actingWithToken($admin)->postJson($consents, [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/consents');
    $check($this->actingWithToken($admin)->getJson($consents), 'GET', '/patients/{patient}/consents');
    $check($this->actingWithToken($admin)->getJson('/api/v1/consents/'.$granted->json('data.id').'/certificate'), 'GET', '/consents/{consent}/certificate');
    $check($this->actingWithToken($admin)->getJson('/api/v1/consents/'.fake()->uuid().'/certificate'), 'GET', '/consents/{consent}/certificate');

    // Revocación de finalidades (CUS-18)
    $revocations = "{$consents}/".$granted->json('data.id').'/revocations';
    $check($this->actingWithToken($admin)->postJson($revocations, ['purposes' => ['notificaciones']]), 'POST', '/patients/{patient}/consents/{consent}/revocations');
    $check($this->actingWithToken($admin)->postJson($revocations, ['purposes' => ['notificaciones']]), 'POST', '/patients/{patient}/consents/{consent}/revocations');
    $check($this->actingWithToken($admin)->postJson("{$consents}/".fake()->uuid().'/revocations', ['purposes' => ['notificaciones']]), 'POST', '/patients/{patient}/consents/{consent}/revocations');

    // Solicitudes ARCO en la clínica (CUS-63)
    $arcoRequests = "/api/v1/patients/{$adult->uuid}/arco-requests";
    $arcoBody = ['type' => 'acceso', 'requested_by' => 'titular', 'description' => 'Solicito la copia de mi historia clínica.', 'confirmation_document_number' => '45678912'];
    $check($this->actingWithToken($admin)->postJson($arcoRequests, $arcoBody, ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/arco-requests');
    $check($this->actingWithToken($admin)->postJson($arcoRequests, [...$arcoBody, 'confirmation_document_number' => '00000000'], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/arco-requests');
    $check($this->actingWithToken($admin)->getJson($arcoRequests), 'GET', '/patients/{patient}/arco-requests');

    // Copia de la historia clínica en la clínica (CUS-62)
    $check($this->actingWithToken($admin)->postJson("/api/v1/patients/{$adult->uuid}/clinical-record-copies", [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/clinical-record-copies');
    $check($this->actingWithToken($admin)->postJson('/api/v1/patients/'.fake()->uuid().'/clinical-record-copies', [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/clinical-record-copies');

    // Atención de solicitudes ARCO por el Oficial de Datos Personales (CUS-64)
    $officer = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'is_data_officer' => true]);
    $arcoDentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $arcoAnswer = ['status' => 'atendida', 'response' => 'Atendimos su solicitud dentro del plazo de la Ley N° 29733.'];
    $access = ArcoFixtures::request($tenant, $adult);
    $correction = ArcoFixtures::request($tenant, $adult, 'rectificacion');
    $cancellation = ArcoFixtures::request($tenant, $adult, 'cancelacion');
    $this->actingWithToken($officer);
    $check($this->getJson('/api/v1/arco-requests'), 'GET', '/arco-requests');
    $check($this->getJson('/api/v1/arco-requests?status=otro'), 'GET', '/arco-requests');
    $check($this->getJson("/api/v1/arco-requests/{$access->uuid}"), 'GET', '/arco-requests/{arcoRequest}');
    $check($this->getJson('/api/v1/arco-requests/'.fake()->uuid()), 'GET', '/arco-requests/{arcoRequest}');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/start"), 'POST', '/arco-requests/{arcoRequest}/start');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/start"), 'POST', '/arco-requests/{arcoRequest}/start');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/assign-correction", ['dentist_id' => $arcoDentist->uuid]), 'POST', '/arco-requests/{arcoRequest}/assign-correction');
    $check($this->postJson("/api/v1/arco-requests/{$correction->uuid}/assign-correction", ['dentist_id' => $arcoDentist->uuid]), 'POST', '/arco-requests/{arcoRequest}/assign-correction');
    $this->postJson("/api/v1/arco-requests/{$correction->uuid}/start")->assertOk();
    $check($this->postJson("/api/v1/arco-requests/{$correction->uuid}/assign-correction", ['dentist_id' => 'x']), 'POST', '/arco-requests/{arcoRequest}/assign-correction');
    $check($this->postJson("/api/v1/arco-requests/{$correction->uuid}/assign-correction", ['dentist_id' => $arcoDentist->uuid]), 'POST', '/arco-requests/{arcoRequest}/assign-correction');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/resolve", ['status' => 'denegada', 'response' => 'Corta']), 'POST', '/arco-requests/{arcoRequest}/resolve');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/resolve", $arcoAnswer), 'POST', '/arco-requests/{arcoRequest}/resolve');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/resolve", $arcoAnswer), 'POST', '/arco-requests/{arcoRequest}/resolve');
    $check($this->postJson("/api/v1/arco-requests/{$access->uuid}/cancellation", [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/arco-requests/{arcoRequest}/cancellation');
    $check($this->postJson("/api/v1/arco-requests/{$cancellation->uuid}/cancellation", [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/arco-requests/{arcoRequest}/cancellation');
    $this->postJson("/api/v1/arco-requests/{$cancellation->uuid}/start")->assertOk();
    $check($this->postJson("/api/v1/arco-requests/{$cancellation->uuid}/resolve", $arcoAnswer), 'POST', '/arco-requests/{arcoRequest}/resolve');

    // Antecedentes médicos
    $history = ['alergias' => ['Penicilina'], 'enfermedades' => [], 'medicamentos' => [], 'observaciones' => null];
    $check($this->actingWithToken($admin)->putJson("/api/v1/patients/{$adult->uuid}/medical-history", $history), 'PUT', '/patients/{patient}/medical-history');
    $check($this->actingWithToken($admin)->putJson("/api/v1/patients/{$adult->uuid}/medical-history", ['alergias' => 'x']), 'PUT', '/patients/{patient}/medical-history');
    $check($this->actingWithToken($admin)->putJson("/api/v1/patients/{$unrepresentedMinor->uuid}/medical-history", $history), 'PUT', '/patients/{patient}/medical-history');

    // Parámetros de la clínica
    Storage::fake('s3');
    $check($this->actingWithToken($admin)->getJson('/api/v1/clinic/settings'), 'GET', '/clinic/settings');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/clinic/settings', ['budget_validity_days' => 45]), 'PATCH', '/clinic/settings');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/clinic/settings', ['budget_validity_days' => 0]), 'PATCH', '/clinic/settings');
    $check($this->actingWithToken($admin)->post('/api/v1/clinic/logo', ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json']), 'POST', '/clinic/logo');
    $check($this->actingWithToken($admin)->postJson('/api/v1/clinic/logo', []), 'POST', '/clinic/logo');

    // Atenciones (el adulto ya tiene consentimiento; el menor sin representante no).
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'cop_number' => '54321']);
    $attentions = "/api/v1/patients/{$adult->uuid}/attentions";
    $opened = $this->actingWithToken($dentist)->postJson($attentions, [], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($opened, 'POST', '/patients/{patient}/attentions');
    $check($this->actingWithToken($dentist)->postJson($attentions, [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/attentions');
    $check($this->actingWithToken($dentist)->postJson("/api/v1/patients/{$unrepresentedMinor->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/attentions');
    $check($this->actingWithToken($admin)->getJson($attentions), 'GET', '/patients/{patient}/attentions');
    $attentionId = $opened->json('data.id');
    $check($this->actingWithToken($admin)->getJson("/api/v1/attentions/{$attentionId}"), 'GET', '/attentions/{attention}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/attentions/'.fake()->uuid()), 'GET', '/attentions/{attention}');
    $close = fn () => $this->actingWithToken($dentist)->postJson("/api/v1/attentions/{$attentionId}/close", [], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($close(), 'POST', '/attentions/{attention}/close');

    // Nota, diagnósticos CIE-10 y adendas
    $check($this->actingWithToken($dentist)->getJson('/api/v1/cie10?q=caries'), 'GET', '/cie10');
    $check($this->actingWithToken($dentist)->getJson('/api/v1/cie10'), 'GET', '/cie10');
    $note = "/api/v1/attentions/{$attentionId}/note";
    $check($this->actingWithToken($dentist)->putJson($note, ['chief_complaint' => 'Dolor al masticar', 'intraoral_exam' => 'Lesión en 36']), 'PUT', '/attentions/{attention}/note');
    $check($this->actingWithToken($dentist)->putJson($note, ['chief_complaint' => str_repeat('a', 2001)]), 'PUT', '/attentions/{attention}/note');
    $diagnoses = "/api/v1/attentions/{$attentionId}/diagnoses";
    $added = $this->actingWithToken($dentist)->postJson($diagnoses, ['cie10_code' => 'K05', 'type' => 'presuntivo']);
    $check($added, 'POST', '/attentions/{attention}/diagnoses');
    $check($this->actingWithToken($dentist)->postJson($diagnoses, ['cie10_code' => 'X00']), 'POST', '/attentions/{attention}/diagnoses');
    $check($this->actingWithToken($dentist)->deleteJson("{$diagnoses}/".$added->json('data.id')), 'DELETE', '/attentions/{attention}/diagnoses/{diagnosis}');
    $check($this->actingWithToken($dentist)->deleteJson("{$diagnoses}/".fake()->uuid()), 'DELETE', '/attentions/{attention}/diagnoses/{diagnosis}');
    $this->actingWithToken($dentist)->postJson($diagnoses, ['cie10_code' => 'K02.1', 'type' => 'definitivo']);
    // Hallazgos y correcciones del odontograma
    ClinicalFixtures::cariesFinding();
    $check($this->actingWithToken($admin)->getJson('/api/v1/finding-catalog'), 'GET', '/finding-catalog');
    $entries = "/api/v1/attentions/{$attentionId}/odontogram-entries";
    $finding = ['tooth' => 36, 'surfaces' => ['O'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'ACTIVA'];
    $recorded = $this->actingWithToken($dentist)->postJson($entries, $finding, ['Idempotency-Key' => (string) Str::uuid()]);
    $check($recorded, 'POST', '/attentions/{attention}/odontogram-entries');
    $check($this->actingWithToken($dentist)->postJson($entries, [...$finding, 'tooth' => 19], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/attentions/{attention}/odontogram-entries');
    $corrections = '/api/v1/odontogram-entries/'.$recorded->json('data.id').'/corrections';
    $check($this->actingWithToken($dentist)->postJson($corrections, ['kind' => 'anulacion', 'reason' => 'Corta'], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/odontogram-entries/{entry}/corrections');
    $check($this->actingWithToken($dentist)->postJson($corrections, [
        'kind' => 'reemplazo', 'reason' => 'Se registró en la pieza equivocada', ...$finding, 'tooth' => 37,
    ], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/odontogram-entries/{entry}/corrections');
    $check($this->actingWithToken($dentist)->postJson($corrections, ['kind' => 'anulacion', 'reason' => 'Segunda corrección'], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/odontogram-entries/{entry}/corrections');

    // Historia clínica y odontograma
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/clinical-record"), 'GET', '/patients/{patient}/clinical-record');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/odontogram"), 'GET', '/patients/{patient}/odontogram');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/odontogram?at=no-es-fecha"), 'GET', '/patients/{patient}/odontogram');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/odontogram/initial"), 'GET', '/patients/{patient}/odontogram/initial');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$unrepresentedMinor->uuid}/odontogram/initial"), 'GET', '/patients/{patient}/odontogram/initial');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/teeth/36/history"), 'GET', '/patients/{patient}/teeth/{tooth}/history');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/teeth/19/history"), 'GET', '/patients/{patient}/teeth/{tooth}/history');

    $addenda = "/api/v1/attentions/{$attentionId}/addenda";
    $check($this->actingWithToken($dentist)->postJson($addenda, ['text' => 'Antes del cierre']), 'POST', '/attentions/{attention}/addenda');
    $check($close(), 'POST', '/attentions/{attention}/close');
    $check($close(), 'POST', '/attentions/{attention}/close');
    $check($this->actingWithToken($dentist)->putJson($note, ['chief_complaint' => 'Otro']), 'PUT', '/attentions/{attention}/note');
    $check($this->actingWithToken($dentist)->postJson($addenda, [
        'text' => 'Control telefónico.', 'diagnoses' => [['cie10_code' => 'K05', 'type' => 'presuntivo']],
    ]), 'POST', '/attentions/{attention}/addenda');
    $check($this->actingWithToken($dentist)->postJson($addenda, []), 'POST', '/attentions/{attention}/addenda');
    $check($this->actingWithToken($admin)->getJson("/api/v1/attentions/{$attentionId}"), 'GET', '/attentions/{attention}');

    // Catálogo de procedimientos (CUS-32)
    $check($this->actingWithToken($admin)->getJson('/api/v1/procedures'), 'GET', '/procedures');
    $created = $this->actingWithToken($admin)->postJson('/api/v1/procedures', [
        'code' => 'RES-01', 'name' => 'Restauración con resina', 'category' => 'Operatoria', 'price' => '150.00',
        'requires_tooth' => true, 'requires_surface' => true, 'resulting_finding_code' => 'RESTAURACION', 'resulting_state_code' => 'R_BUENO',
    ]);
    $check($created, 'POST', '/procedures');
    $check($this->actingWithToken($admin)->postJson('/api/v1/procedures', ['code' => 'RES-01']), 'POST', '/procedures');
    $procedureId = $created->json('data.id');
    $check($this->actingWithToken($admin)->patchJson("/api/v1/procedures/{$procedureId}", ['price' => '180.50']), 'PATCH', '/procedures/{procedure}');
    $check($this->actingWithToken($admin)->patchJson("/api/v1/procedures/{$procedureId}", ['price' => '-1']), 'PATCH', '/procedures/{procedure}');
    $used = PlanItem::factory()->create(['tenant_id' => $tenant->id]);
    $usedId = TenantContext::run($tenant, fn () => Procedure::query()->whereKey($used->procedure_id)->value('uuid'));
    $check($this->actingWithToken($admin)->deleteJson("/api/v1/procedures/{$usedId}"), 'DELETE', '/procedures/{procedure}');
    $check($this->actingWithToken($admin)->deleteJson("/api/v1/procedures/{$procedureId}"), 'DELETE', '/procedures/{procedure}');
    $check($this->actingWithToken($admin)->deleteJson('/api/v1/procedures/'.fake()->uuid()), 'DELETE', '/procedures/{procedure}');

    // Plan de tratamiento, pendientes de decisión y no tratar (CUS-33, CUS-34, CUS-40). El hallazgo
    // rojo vigente del adulto es el reemplazo en la pieza 37.
    $pending = $this->actingWithToken($dentist)->getJson("/api/v1/patients/{$adult->uuid}/pending-findings");
    $check($pending, 'GET', '/patients/{patient}/pending-findings');
    $findingId = $pending->json('data.0.id');
    $planProcedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_surface' => false])->uuid;
    $plans = "/api/v1/patients/{$adult->uuid}/treatment-plans";
    $createdPlan = $this->actingWithToken($dentist)->postJson($plans, ['title' => 'Plan de contrato', 'items' => [
        ['procedure_id' => $planProcedure, 'tooth' => 37, 'finding_ids' => [$findingId]],
    ]], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($createdPlan, 'POST', '/patients/{patient}/treatment-plans');
    $check($this->actingWithToken($dentist)->postJson($plans, ['title' => 'Sin pieza', 'items' => [['procedure_id' => $planProcedure]]], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/patients/{patient}/treatment-plans');
    $check($this->actingWithToken($admin)->getJson($plans), 'GET', '/patients/{patient}/treatment-plans');
    $plan = '/api/v1/treatment-plans/'.$createdPlan->json('data.id');
    $planItem = '/api/v1/plan-items/'.$createdPlan->json('data.items.0.id');
    $check($this->actingWithToken($admin)->getJson($plan), 'GET', '/treatment-plans/{plan}');
    $check($this->actingWithToken($dentist)->patchJson($plan, ['title' => 'Plan de contrato corregido']), 'PATCH', '/treatment-plans/{plan}');
    $check($this->actingWithToken($dentist)->patchJson($plan, ['title' => str_repeat('a', 151)]), 'PATCH', '/treatment-plans/{plan}');
    $added = $this->actingWithToken($dentist)->postJson("{$plan}/items", ['items' => [['procedure_id' => $planProcedure, 'tooth' => 16]]]);
    $check($added, 'POST', '/treatment-plans/{plan}/items');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/items", ['items' => [['procedure_id' => $planProcedure, 'tooth' => 19]]]), 'POST', '/treatment-plans/{plan}/items');
    $secondItem = '/api/v1/plan-items/'.$added->json('data.items.1.id');
    $check($this->actingWithToken($dentist)->patchJson($secondItem, ['quantity' => 2, 'session_number' => 2]), 'PATCH', '/plan-items/{item}');
    $check($this->actingWithToken($dentist)->patchJson($secondItem, ['tooth' => 19]), 'PATCH', '/plan-items/{item}');
    $check($this->actingWithToken($dentist)->deleteJson($secondItem), 'DELETE', '/plan-items/{item}');
    $noTreat = "/api/v1/odontogram-entries/{$findingId}/no-treat";
    $check($this->actingWithToken($dentist)->postJson($noTreat, ['reason' => 'corto']), 'POST', '/odontogram-entries/{entry}/no-treat');
    $check($this->actingWithToken($dentist)->postJson($noTreat, ['reason' => 'Ya figura en el plan']), 'POST', '/odontogram-entries/{entry}/no-treat');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/propose"), 'POST', '/treatment-plans/{plan}/propose');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/propose"), 'POST', '/treatment-plans/{plan}/propose');
    $check($this->actingWithToken($dentist)->patchJson($plan, ['title' => 'Plan propuesto']), 'PATCH', '/treatment-plans/{plan}');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/reopen"), 'POST', '/treatment-plans/{plan}/reopen');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/reopen"), 'POST', '/treatment-plans/{plan}/reopen');
    $check($this->actingWithToken($admin)->postJson("{$planItem}/discard", []), 'POST', '/plan-items/{item}/discard');
    $check($this->actingWithToken($admin)->postJson("{$planItem}/discard", ['reason' => 'El paciente desistió']), 'POST', '/plan-items/{item}/discard');
    $check($this->actingWithToken($admin)->postJson("{$planItem}/discard", ['reason' => 'Otra vez']), 'POST', '/plan-items/{item}/discard');
    $check($this->actingWithToken($dentist)->postJson($noTreat, ['reason' => 'El paciente no desea tratarlo']), 'POST', '/odontogram-entries/{entry}/no-treat');
    $check($this->actingWithToken($dentist)->postJson("{$plan}/propose"), 'POST', '/treatment-plans/{plan}/propose');
    $check($this->actingWithToken($dentist)->patchJson($planItem, ['quantity' => 3]), 'PATCH', '/plan-items/{item}');
    $check($this->actingWithToken($admin)->getJson("{$plan}/cancellation-preview"), 'GET', '/treatment-plans/{plan}/cancellation-preview');
    $check($this->actingWithToken($admin)->postJson("{$plan}/cancel", []), 'POST', '/treatment-plans/{plan}/cancel');
    $check($this->actingWithToken($admin)->postJson("{$plan}/cancel", ['reason' => 'Plan de prueba del contrato']), 'POST', '/treatment-plans/{plan}/cancel');
    $check($this->actingWithToken($admin)->postJson("{$plan}/cancel", ['reason' => 'Segunda cancelación']), 'POST', '/treatment-plans/{plan}/cancel');
    $check($this->actingWithToken($admin)->getJson("{$plan}/cancellation-preview"), 'GET', '/treatment-plans/{plan}/cancellation-preview');
    $check($this->actingWithToken($dentist)->deleteJson($planItem), 'DELETE', '/plan-items/{item}');

    // Consentimientos informados de procedimientos (CUS-82, CUS-83)
    // El contrato ya acumula cientos de solicitudes del mismo usuario; se desactiva el throttle
    // (DD-19) para que este bloque no termine en 429 (no se ejerce ninguna aserción de límite aquí).
    $this->withoutMiddleware(ThrottleRequests::class);
    $consentProcedure = TenantContext::run($tenant, fn () => Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true]));
    $consentItem = TenantContext::run($tenant, function () use ($tenant, $adult, $dentist, $consentProcedure): PlanItem {
        $consentPlan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $adult->id, 'created_by' => $dentist->id,
        ]);

        return PlanItem::factory()->create([
            'tenant_id' => $tenant->id, 'treatment_plan_id' => $consentPlan->id, 'procedure_id' => $consentProcedure->id, 'tooth' => 16,
        ]);
    });
    $templates = '/api/v1/informed-consent-templates';
    $createdTemplate = $this->actingWithToken($admin)->postJson($templates, [
        'title' => 'Consentimiento de extracción',
        'body' => 'El paciente {{paciente}} autoriza {{procedimiento}} en {{pieza}}. Riesgos: {{riesgos}}. Alternativas: {{alternativas}}. Informó: {{odontologo}}.',
        'procedures' => [$consentProcedure->uuid],
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($createdTemplate, 'POST', '/informed-consent-templates');
    $check($this->actingWithToken($admin)->postJson($templates, [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/informed-consent-templates');
    $templateId = $createdTemplate->json('data.id');
    $check($this->actingWithToken($dentist)->getJson($templates), 'GET', '/informed-consent-templates');
    $check($this->actingWithToken($admin)->putJson("{$templates}/{$templateId}", ['title' => 'CI de extraccion']), 'PUT', '/informed-consent-templates/{template}');
    $check($this->actingWithToken($admin)->putJson("{$templates}/{$templateId}", ['body' => 'Nuevo cuerpo {{paciente}}']), 'PUT', '/informed-consent-templates/{template}');
    $itemConsents = "/api/v1/plan-items/{$consentItem->uuid}/informed-consents";
    $check($this->actingWithToken($dentist)->getJson("{$itemConsents}/preview?riesgos=Riesgo en aclaración"), 'GET', '/plan-items/{item}/informed-consents/preview');
    $signedConsent = $this->actingWithToken($dentist)->postJson($itemConsents, [
        'channel' => 'dispositivo', 'confirmation_document_number' => (string) $adult->document_number, 'riesgos' => 'Sangrado leve', 'alternativas' => 'No tratar',
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($signedConsent, 'POST', '/plan-items/{item}/informed-consents');
    $check($this->actingWithToken($dentist)->postJson($itemConsents, [], ['Idempotency-Key' => (string) Str::uuid()]), 'POST', '/plan-items/{item}/informed-consents');
    $revokeEndpoint = '/api/v1/informed-consents/'.$signedConsent->json('data.id').'/revoke';
    $check($this->actingWithToken($dentist)->postJson($revokeEndpoint, ['reason' => 'Decisión del paciente']), 'POST', '/informed-consents/{informedConsent}/revoke');
    $check($this->actingWithToken($dentist)->postJson($revokeEndpoint, ['reason' => 'Otra vez']), 'POST', '/informed-consents/{informedConsent}/revoke');
    $check($this->actingWithToken($dentist)->postJson('/api/v1/informed-consents/'.fake()->uuid().'/revoke', ['reason' => 'ajena']), 'POST', '/informed-consents/{informedConsent}/revoke');
    $check($this->actingWithToken($admin)->postJson("{$templates}/{$templateId}/deactivate"), 'POST', '/informed-consent-templates/{template}/deactivate');

    // Presupuestos (CUS-35, CUS-36). El plan del contrato quedó cancelado más arriba.
    $receptionist = User::factory()->for($tenant)->create(['role' => 'receptionist']);
    [$budgetPlan, $budgetProcedure] = TenantContext::run($tenant, function () use ($tenant, $adult, $dentist): array {
        $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'price' => '150.00']);
        $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $adult->id, 'created_by' => $dentist->id, 'status' => 'propuesto']);
        PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id, 'tooth' => 16]);

        return [$plan, $procedure];
    });
    $newBudget = fn (string $planPath, array $payload = []) => $this->actingWithToken($receptionist)
        ->postJson("{$planPath}/budgets", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    $draft = $newBudget("/api/v1/treatment-plans/{$budgetPlan->uuid}");
    $check($draft, 'POST', '/treatment-plans/{plan}/budgets');
    $check($newBudget("/api/v1/treatment-plans/{$budgetPlan->uuid}", ['plan_item_ids' => [fake()->uuid()]]), 'POST', '/treatment-plans/{plan}/budgets');
    $check($newBudget($plan), 'POST', '/treatment-plans/{plan}/budgets');
    $budget = '/api/v1/budgets/'.$draft->json('data.id');
    $line = "{$budget}/lines/".$draft->json('data.lines.0.id');
    $check($this->actingWithToken($receptionist)->patchJson($line, ['discount_pct' => 50, 'discount_reason' => 'Fuera del tope']), 'PATCH', '/budgets/{budget}/lines/{line}');
    $check($this->actingWithToken($receptionist)->patchJson($line, ['discount_pct' => 5]), 'PATCH', '/budgets/{budget}/lines/{line}');
    $check($this->actingWithToken($receptionist)->patchJson($line, ['discount_pct' => 5, 'discount_reason' => 'Convenio']), 'PATCH', '/budgets/{budget}/lines/{line}');
    $check($this->actingWithToken($receptionist)->getJson("{$budget}/pdf"), 'GET', '/budgets/{budget}/pdf');
    $check($this->actingWithToken($receptionist)->postJson("{$budget}/pdf/regenerate"), 'POST', '/budgets/{budget}/pdf/regenerate');
    $issue = fn (string $budgetPath) => $this->actingWithToken($receptionist)
        ->postJson("{$budgetPath}/issue", [], ['Idempotency-Key' => (string) Str::uuid()]);
    $inactiveDraft = '/api/v1/budgets/'.$newBudget("/api/v1/treatment-plans/{$budgetPlan->uuid}")->json('data.id');
    TenantContext::run($tenant, fn () => $budgetProcedure->forceFill(['is_active' => false])->save());
    $check($issue($inactiveDraft), 'POST', '/budgets/{budget}/issue');
    TenantContext::run($tenant, fn () => $budgetProcedure->forceFill(['is_active' => true])->save());
    $check($issue($budget), 'POST', '/budgets/{budget}/issue');
    $check($issue($budget), 'POST', '/budgets/{budget}/issue');
    $check($this->actingWithToken($receptionist)->patchJson($line, ['discount_pct' => 0]), 'PATCH', '/budgets/{budget}/lines/{line}');
    $check($this->actingWithToken($receptionist)->deleteJson($budget), 'DELETE', '/budgets/{budget}');
    $corrections = fn (string $budgetPath) => $this->actingWithToken($receptionist)
        ->postJson("{$budgetPath}/corrections", [], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($corrections($budget), 'POST', '/budgets/{budget}/corrections');
    $check($corrections($inactiveDraft), 'POST', '/budgets/{budget}/corrections');
    $check($this->actingWithToken($receptionist)->deleteJson($inactiveDraft), 'DELETE', '/budgets/{budget}');
    $check($this->actingWithToken($admin)->getJson($budget), 'GET', '/budgets/{budget}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/budgets/'.fake()->uuid()), 'GET', '/budgets/{budget}');
    $check($this->actingWithToken($admin)->getJson("/api/v1/patients/{$adult->uuid}/budgets"), 'GET', '/patients/{patient}/budgets');
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();
    $check($this->actingWithToken($receptionist)->getJson("{$budget}/pdf"), 'GET', '/budgets/{budget}/pdf');
    $pdfDocument = TenantContext::run($tenant, fn () => DB::table('generated_documents')->where('kind', 'presupuesto')->orderBy('id')->first(['id', 'uuid']));
    $check($this->actingWithToken($admin)->getJson("/api/v1/documents/{$pdfDocument->uuid}"), 'GET', '/documents/{document}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/documents/'.fake()->uuid()), 'GET', '/documents/{document}');
    TenantContext::run($tenant, fn () => DB::table('generated_documents')->where('id', $pdfDocument->id)->update(['status' => 'fallido']));
    $check($this->actingWithToken($receptionist)->postJson("{$budget}/pdf/regenerate"), 'POST', '/budgets/{budget}/pdf/regenerate');

    // Decisión presencial sobre el presupuesto emitido (CUS-37).
    $adultDocument = TenantContext::run($tenant, fn () => Patient::query()->whereKey($adult->id)->sole()->document_number);
    $decide = fn (array $payload) => $this->actingWithToken($receptionist)->post("{$budget}/decision", [
        'decision' => 'aceptado', 'signer' => 'titular', 'signer_document_number' => $adultDocument, ...$payload,
    ], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]);
    $check($decide(['signer_document_number' => '00000000']), 'POST', '/budgets/{budget}/decision');
    $check($decide([]), 'POST', '/budgets/{budget}/decision');
    $check($decide([]), 'POST', '/budgets/{budget}/decision');

    // Procedimiento realizado y de urgencia (CUS-39), en una atención nueva del adulto.
    $openAttention = $this->actingWithToken($dentist)->postJson("/api/v1/patients/{$adult->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()])->json('data.id');
    $acceptedItem = TenantContext::run($tenant, fn () => PlanItem::query()->where('treatment_plan_id', $budgetPlan->id)->sole()->uuid);
    $perform = fn (array $payload) => $this->actingWithToken($dentist)->postJson("/api/v1/plan-items/{$acceptedItem}/performed-procedures", [
        'attention_id' => $openAttention, 'quantity' => 1, ...$payload,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($perform(['quantity' => 5]), 'POST', '/plan-items/{item}/performed-procedures');
    $check($perform(['observations' => 'Sin complicaciones']), 'POST', '/plan-items/{item}/performed-procedures');
    $check($perform([]), 'POST', '/plan-items/{item}/performed-procedures');
    $urgent = fn (string $attentionUuid, array $payload) => $this->actingWithToken($dentist)->postJson("/api/v1/attentions/{$attentionUuid}/urgent-procedures", [
        'procedure_id' => $budgetProcedure->uuid, 'tooth' => 26, 'quantity' => 1,
        'signer' => 'titular', 'signer_document_number' => $adultDocument, ...$payload,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $check($urgent($openAttention, ['signer_document_number' => '00000000']), 'POST', '/attentions/{attention}/urgent-procedures');
    $check($urgent($openAttention, []), 'POST', '/attentions/{attention}/urgent-procedures');
    $check($urgent($attentionId, []), 'POST', '/attentions/{attention}/urgent-procedures');

    // Agenda (TASK-065): horario, bloqueos y tipos de cita.
    $idempotent = fn () => ['Idempotency-Key' => (string) Str::uuid()];
    $scheduleDentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $schedulePath = "/api/v1/dentists/{$scheduleDentist->uuid}/work-schedules";
    $today = now('America/Lima')->toDateString();
    $check($this->actingWithToken($admin)->putJson($schedulePath, ['valid_from' => $today, 'slots' => [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
    ]], $idempotent()), 'PUT', '/dentists/{dentist}/work-schedules');
    $check($this->actingWithToken($admin)->putJson($schedulePath, ['valid_from' => $today, 'slots' => [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'], ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '14:00'],
    ]], $idempotent()), 'PUT', '/dentists/{dentist}/work-schedules');
    $check($this->actingWithToken($admin)->getJson($schedulePath), 'GET', '/dentists/{dentist}/work-schedules');
    $check($this->actingWithToken($admin)->getJson('/api/v1/dentists/'.fake()->uuid().'/work-schedules'), 'GET', '/dentists/{dentist}/work-schedules');
    $blockStart = now()->addDays(10)->startOfHour();
    $blockPayload = ['dentist_id' => $scheduleDentist->uuid, 'starts_at' => $blockStart->toIso8601String(), 'ends_at' => $blockStart->copy()->addHours(2)->toIso8601String()];
    $check($this->actingWithToken($admin)->postJson('/api/v1/schedule-blocks/impact', $blockPayload), 'POST', '/schedule-blocks/impact');
    $check($this->actingWithToken($admin)->postJson('/api/v1/schedule-blocks/impact', []), 'POST', '/schedule-blocks/impact');
    $blockCreated = $this->actingWithToken($admin)->postJson('/api/v1/schedule-blocks', [...$blockPayload, 'kind' => 'capacitacion', 'reason' => 'Curso'], $idempotent());
    $check($blockCreated, 'POST', '/schedule-blocks');
    $check($this->actingWithToken($admin)->postJson('/api/v1/schedule-blocks', $blockPayload, $idempotent()), 'POST', '/schedule-blocks');
    $check($this->actingWithToken($admin)->getJson('/api/v1/schedule-blocks'), 'GET', '/schedule-blocks');
    $check($this->actingWithToken($admin)->deleteJson('/api/v1/schedule-blocks/'.$blockCreated->json('data.id')), 'DELETE', '/schedule-blocks/{block}');
    $check($this->actingWithToken($admin)->deleteJson('/api/v1/schedule-blocks/'.fake()->uuid()), 'DELETE', '/schedule-blocks/{block}');
    $typeCreated = $this->actingWithToken($admin)->postJson('/api/v1/appointment-types', ['name' => 'Control', 'default_duration_min' => 30, 'color' => '#1D4ED8']);
    $check($typeCreated, 'POST', '/appointment-types');
    $check($this->actingWithToken($admin)->postJson('/api/v1/appointment-types', ['name' => 'Control', 'default_duration_min' => 12]), 'POST', '/appointment-types');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/appointment-types/'.$typeCreated->json('data.id'), ['portal_visible' => true]), 'PATCH', '/appointment-types/{type}');
    $check($this->actingWithToken($admin)->patchJson('/api/v1/appointment-types/'.$typeCreated->json('data.id'), ['color' => 'azul']), 'PATCH', '/appointment-types/{type}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/appointment-types'), 'GET', '/appointment-types');
    $check($this->actingWithToken($admin)->getJson('/api/v1/availability?'.http_build_query([
        'appointment_type_id' => $typeCreated->json('data.id'), 'from' => $today, 'to' => now('America/Lima')->addDays(6)->toDateString(),
    ])), 'GET', '/availability');
    $check($this->actingWithToken($admin)->getJson('/api/v1/availability?duration_min=12'), 'GET', '/availability');

    // Citas (TASK-067): reserva, reprogramación, confirmación, cancelación y enlace público.
    $nextMonday = now('America/Lima')->next('Monday')->toDateString();
    $bookAt = fn (string $time) => $this->actingWithToken($admin)->postJson('/api/v1/appointments', [
        'patient_id' => $patient->uuid, 'dentist_id' => $scheduleDentist->uuid,
        'appointment_type_id' => $typeCreated->json('data.id'), 'starts_at' => Carbon::parse("{$nextMonday} {$time}", 'America/Lima')->toIso8601String(),
    ], $idempotent());
    $booked = $bookAt('09:00');
    $check($booked, 'POST', '/appointments');
    $check($bookAt('09:15'), 'POST', '/appointments');
    $check($bookAt('12:45'), 'POST', '/appointments');
    $check($this->actingWithToken($admin)->postJson('/api/v1/appointments', [], $idempotent()), 'POST', '/appointments');
    $appointmentPath = '/api/v1/appointments/'.$booked->json('data.id');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/reschedule", [
        'starts_at' => Carbon::parse("{$nextMonday} 10:00", 'America/Lima')->toIso8601String(),
    ], $idempotent()), 'POST', '/appointments/{appointment}/reschedule');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/reschedule", [
        'starts_at' => Carbon::parse("{$nextMonday} 18:00", 'America/Lima')->toIso8601String(),
    ], $idempotent()), 'POST', '/appointments/{appointment}/reschedule');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/confirm"), 'POST', '/appointments/{appointment}/confirm');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/cancel", []), 'POST', '/appointments/{appointment}/cancel');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/cancel", ['cancel_reason' => 'paciente']), 'POST', '/appointments/{appointment}/cancel');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/cancel", ['cancel_reason' => 'paciente']), 'POST', '/appointments/{appointment}/cancel');
    $check($this->actingWithToken($admin)->postJson("{$appointmentPath}/confirm"), 'POST', '/appointments/{appointment}/confirm');
    $linkedId = $bookAt('11:00')->json('data.id');
    $linked = TenantContext::run($tenant, fn () => Appointment::query()->where('uuid', $linkedId)->sole());
    $linkToken = TenantContext::run($tenant, fn () => app(OneTimeTokenService::class)->issue($linked, TokenPurpose::AppointmentConfirmation, $linked->starts_at)['token']);
    app('auth')->forgetGuards();
    $check($this->postJson("/api/v1/public/appointment-confirmations/{$linkToken}"), 'POST', '/public/appointment-confirmations/{token}');
    $check($this->postJson("/api/v1/public/appointment-confirmations/{$linkToken}"), 'POST', '/public/appointment-confirmations/{token}');

    // Agenda, check-in y sala de espera (TASK-069).
    $check($this->actingWithToken($admin)->getJson("/api/v1/appointments?from={$today}&to={$nextMonday}"), 'GET', '/appointments');
    $check($this->actingWithToken($admin)->getJson('/api/v1/appointments?status=otro'), 'GET', '/appointments');
    $check($this->actingWithToken($admin)->getJson("/api/v1/appointments/{$linkedId}"), 'GET', '/appointments/{appointment}');
    $check($this->actingWithToken($admin)->getJson('/api/v1/appointments/'.fake()->uuid()), 'GET', '/appointments/{appointment}');
    $soon = Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'dentist_id' => $scheduleDentist->id,
        'patient_id' => Patient::factory()->for($tenant)->create()->id,
        'starts_at' => now()->addMinutes(30)->startOfMinute(), 'ends_at' => now()->addMinutes(60)->startOfMinute(),
    ]);
    $check($this->actingWithToken($admin)->postJson("/api/v1/appointments/{$linkedId}/check-in", [], $idempotent()), 'POST', '/appointments/{appointment}/check-in');
    $check($this->actingWithToken($admin)->postJson("/api/v1/appointments/{$soon->uuid}/check-in", [], $idempotent()), 'POST', '/appointments/{appointment}/check-in');
    $check($this->actingWithToken($admin)->postJson("/api/v1/appointments/{$soon->uuid}/check-in", [], $idempotent()), 'POST', '/appointments/{appointment}/check-in');
    $check($this->actingWithToken($admin)->getJson('/api/v1/waiting-room'), 'GET', '/waiting-room');

    // Bandeja in-app (TASK-070).
    $notice = TenantContext::run($tenant, fn () => app(NotificationService::class)->notifyInApp(
        NotificationEvent::PresupuestoAceptado, $admin, ['number' => 'P-000009', 'total' => '150.00'], ['budget' => 'https://app.test/p/9'],
    ));
    $check($this->actingWithToken($admin)->getJson('/api/v1/notifications'), 'GET', '/notifications');
    $check($this->actingWithToken($admin)->postJson("/api/v1/notifications/{$notice->uuid}/read"), 'POST', '/notifications/{notification}/read');
    $check($this->actingWithToken($admin)->postJson('/api/v1/notifications/'.fake()->uuid().'/read'), 'POST', '/notifications/{notification}/read');
    $check($this->actingWithToken($admin)->postJson('/api/v1/notifications/read-all'), 'POST', '/notifications/read-all');

    // Variables de riesgo (TASK-079), en una clínica con plan que incluye riesgo.
    $riskTenant = Tenant::factory()->plan('pro')->create();
    $riskDentist = User::factory()->for($riskTenant)->create(['role' => 'dentist']);
    $riskReceptionist = User::factory()->for($riskTenant)->create(['role' => 'receptionist']);
    $riskPatient = RiskFixtures::patient($riskTenant);
    $riskPath = "/api/v1/patients/{$riskPatient->uuid}/risk-variable-sets";
    $riskSet = $this->actingWithToken($riskDentist)->postJson($riskPath, ['clinical' => RiskFixtures::clinical()]);
    $check($riskSet, 'POST', '/patients/{patient}/risk-variable-sets');
    $check($this->actingWithToken($riskDentist)->postJson($riskPath, ['clinical' => RiskFixtures::clinical(['cpod' => 40])]), 'POST', '/patients/{patient}/risk-variable-sets');
    $check($this->actingWithToken($riskReceptionist)->postJson($riskPath, ['clinical' => RiskFixtures::clinical()]), 'POST', '/patients/{patient}/risk-variable-sets');
    $check($this->actingWithToken($riskReceptionist)->getJson($riskPath), 'GET', '/patients/{patient}/risk-variable-sets');
    $riskSetPath = '/api/v1/risk-variable-sets/'.$riskSet->json('data.id');
    $check($this->actingWithToken($riskDentist)->patchJson($riskSetPath, ['behavioral' => RiskFixtures::behavioral()]), 'PATCH', '/risk-variable-sets/{set}');
    $check($this->actingWithToken($riskDentist)->patchJson($riskSetPath, ['behavioral' => RiskFixtures::behavioral(['brushing_per_day' => '9'])]), 'PATCH', '/risk-variable-sets/{set}');

    // Predicción de riesgo (TASK-081) con el motor simulado.
    $riskAdmin = User::factory()->for($riskTenant)->create(['role' => 'clinic_admin']);
    app(CircuitBreaker::class)->reset();
    ModelVersion::factory()->active()->create(['version' => '1.0.0']);
    MlEngine::predicts(0.7);
    $this->actingWithToken($riskReceptionist)->postJson($riskPath, ['sociodemographic' => RiskFixtures::sociodemographic()]);
    $predictPath = "/api/v1/patients/{$riskPatient->uuid}/risk-predictions";
    $completeSet = ['risk_variable_set_id' => $this->actingWithToken($riskDentist)->getJson($riskPath)->json('data.0.id')];
    $predicted = $this->actingWithToken($riskDentist)->postJson($predictPath, $completeSet, $idempotent());
    $check($predicted, 'POST', '/patients/{patient}/risk-predictions');
    $check($this->actingWithToken($riskDentist)->postJson($predictPath, $completeSet, $idempotent()), 'POST', '/patients/{patient}/risk-predictions');
    $check($this->actingWithToken($riskDentist)->postJson($predictPath, ['risk_variable_set_id' => fake()->uuid()], $idempotent()), 'POST', '/patients/{patient}/risk-predictions');
    $check($this->actingWithToken($riskDentist)->getJson('/api/v1/risk-predictions/'.$predicted->json('data.id')), 'GET', '/risk-predictions/{prediction}');
    $check($this->actingWithToken($riskAdmin)->getJson('/api/v1/risk-predictions/'.$predicted->json('data.id')), 'GET', '/risk-predictions/{prediction}');
    $check($this->actingWithToken($riskDentist)->getJson('/api/v1/risk-predictions/'.fake()->uuid()), 'GET', '/risk-predictions/{prediction}');
    ModelVersion::query()->update(['status' => 'retirada']);
    $check($this->actingWithToken($riskDentist)->postJson($predictPath, $completeSet, $idempotent()), 'POST', '/patients/{patient}/risk-predictions');

    // Alertas de riesgo (TASK-082): la predicción de 0,70 abrió una alerta para el odontólogo.
    $check($this->actingWithToken($riskDentist)->getJson('/api/v1/risk-alerts'), 'GET', '/risk-alerts');
    $acknowledgePath = '/api/v1/risk-alerts/'.$predicted->json('data.alert_id').'/acknowledge';
    $check($this->actingWithToken($riskDentist)->postJson($acknowledgePath, []), 'POST', '/risk-alerts/{alert}/acknowledge');
    $justified = ['action' => 'justificacion', 'justification' => 'Paciente con control preventivo vigente en otra sede.'];
    $check($this->actingWithToken($riskDentist)->postJson($acknowledgePath, $justified), 'POST', '/risk-alerts/{alert}/acknowledge');
    $check($this->actingWithToken($riskDentist)->postJson($acknowledgePath, $justified), 'POST', '/risk-alerts/{alert}/acknowledge');
    $check($this->actingWithToken($riskDentist)->postJson('/api/v1/risk-alerts/'.fake()->uuid().'/acknowledge', $justified), 'POST', '/risk-alerts/{alert}/acknowledge');

    // Portal del paciente (TASK-085).
    [$portalUser, $portalPatient] = PortalFixtures::holder($tenant, withConsent: false);
    $check($this->actingWithToken($portalUser)->getJson('/api/v1/portal/subjects'), 'GET', '/portal/subjects');

    // Consentimiento e historial por pieza en el portal (TASK-088).
    $portalBase = "/api/v1/portal/patients/{$portalPatient->uuid}";
    $check($this->getJson("{$portalBase}/consents/preview"), 'GET', '/portal/patients/{patient}/consents/preview');
    $check($this->postJson("{$portalBase}/consents", ['purpose_care' => false], $idempotent()), 'POST', '/portal/patients/{patient}/consents');
    $check($this->postJson("{$portalBase}/consents", ['purpose_care' => true], $idempotent()), 'POST', '/portal/patients/{patient}/consents');
    $check($this->getJson("{$portalBase}/consents"), 'GET', '/portal/patients/{patient}/consents');
    [$revokingUser, $revokingPatient] = PortalFixtures::holder($tenant);
    $revokingConsent = $this->actingWithToken($revokingUser)->getJson("/api/v1/portal/patients/{$revokingPatient->uuid}/consents")->json('data.0.id');
    $portalRevocations = "/api/v1/portal/patients/{$revokingPatient->uuid}/consents/{$revokingConsent}/revocations";
    $check($this->postJson($portalRevocations, ['purposes' => ['encuestas']]), 'POST', '/portal/patients/{patient}/consents/{consent}/revocations');
    $check($this->postJson($portalRevocations, ['purposes' => ['atencion']]), 'POST', '/portal/patients/{patient}/consents/{consent}/revocations');
    $check($this->postJson($portalRevocations, ['purposes' => ['atencion']]), 'POST', '/portal/patients/{patient}/consents/{consent}/revocations');
    $this->actingWithToken($portalUser);
    // Solicitudes ARCO desde el portal (CUS-63)
    $check($this->postJson("{$portalBase}/arco-requests", ['type' => 'oposicion', 'description' => 'No deseo recibir encuestas.'], $idempotent()), 'POST', '/portal/patients/{patient}/arco-requests');
    $check($this->postJson("{$portalBase}/arco-requests", ['type' => 'otro'], $idempotent()), 'POST', '/portal/patients/{patient}/arco-requests');
    $check($this->getJson("{$portalBase}/arco-requests"), 'GET', '/portal/patients/{patient}/arco-requests');
    // Copia de la historia clínica desde el portal (CUS-62)
    $portalCopy = $this->postJson("{$portalBase}/clinical-record-copies", [], $idempotent());
    $check($portalCopy, 'POST', '/portal/patients/{patient}/clinical-record-copies');
    $check($this->getJson('/api/v1/portal/documents/'.$portalCopy->json('data.id')), 'GET', '/portal/documents/{document}');
    $check($this->getJson('/api/v1/portal/documents/'.fake()->uuid()), 'GET', '/portal/documents/{document}');
    $check($this->getJson("{$portalBase}/teeth/36/history"), 'GET', '/portal/patients/{patient}/teeth/{tooth}/history');
    $check($this->getJson("{$portalBase}/teeth/19/history"), 'GET', '/portal/patients/{patient}/teeth/{tooth}/history');

    // Presupuestos en el portal (TASK-086).
    $portalBudget = PortalFixtures::issuedBudget($tenant, $portalPatient);
    $this->actingWithToken($portalUser);
    $check($this->getJson("{$portalBase}/budgets"), 'GET', '/portal/patients/{patient}/budgets');
    $check($this->getJson("/api/v1/portal/budgets/{$portalBudget}"), 'GET', '/portal/budgets/{budget}');
    $check($this->getJson("/api/v1/portal/budgets/{$portalBudget}/pdf"), 'GET', '/portal/budgets/{budget}/pdf');
    $check($this->postJson("/api/v1/portal/budgets/{$portalBudget}/decision", ['decision' => 'quizá'], $idempotent()), 'POST', '/portal/budgets/{budget}/decision');
    $check($this->postJson("/api/v1/portal/budgets/{$portalBudget}/decision", ['decision' => 'aceptado'], $idempotent()), 'POST', '/portal/budgets/{budget}/decision');
    $check($this->postJson("/api/v1/portal/budgets/{$portalBudget}/decision", ['decision' => 'aceptado'], $idempotent()), 'POST', '/portal/budgets/{budget}/decision');
    $check($this->getJson('/api/v1/portal/budgets/'.fake()->uuid()), 'GET', '/portal/budgets/{budget}');

    // Citas en el portal (TASK-087), con su propia clínica en Lima y el autoagendamiento activo.
    $portalClinic = PortalFixtures::bookingClinic();
    $portalAppointment = PortalFixtures::clinicAppointment($portalClinic, now('America/Lima')->addDays(3)->setTime(9, 0)->toDateTimeString());
    $this->actingWithToken($portalClinic['user']);
    $portalDay = now('America/Lima')->addDays(7)->next('Monday');
    $availability = ['appointment_type_id' => $portalClinic['type']->uuid, 'from' => $portalDay->toDateString(), 'to' => $portalDay->toDateString()];
    $check($this->getJson('/api/v1/portal/availability?'.http_build_query($availability)), 'GET', '/portal/availability');
    $check($this->getJson('/api/v1/portal/availability'), 'GET', '/portal/availability');
    $check($this->getJson('/api/v1/portal/availability?'.http_build_query([...$availability, 'appointment_type_id' => $portalClinic['internalType']->uuid])), 'GET', '/portal/availability');
    $portalAppointments = "/api/v1/portal/patients/{$portalClinic['patient']->uuid}/appointments";
    $portalBooking = ['appointment_type_id' => $portalClinic['type']->uuid, 'dentist_id' => $portalClinic['dentist']->uuid, 'starts_at' => $portalDay->setTime(10, 0)->toIso8601String()];
    $check($this->postJson($portalAppointments, $portalBooking, $idempotent()), 'POST', '/portal/patients/{patient}/appointments');
    $check($this->postJson($portalAppointments, $portalBooking, $idempotent()), 'POST', '/portal/patients/{patient}/appointments');
    $check($this->postJson($portalAppointments, [...$portalBooking, 'starts_at' => now()->addHour()->toIso8601String()], $idempotent()), 'POST', '/portal/patients/{patient}/appointments');
    $check($this->getJson($portalAppointments), 'GET', '/portal/patients/{patient}/appointments');
    $portalAppointmentPath = "/api/v1/portal/appointments/{$portalAppointment->uuid}";
    $check($this->postJson("{$portalAppointmentPath}/confirm"), 'POST', '/portal/appointments/{appointment}/confirm');
    $check($this->postJson("{$portalAppointmentPath}/reschedule", ['starts_at' => $portalDay->setTime(11, 0)->toIso8601String()], $idempotent()), 'POST', '/portal/appointments/{appointment}/reschedule');
    $check($this->postJson("{$portalAppointmentPath}/reschedule", ['starts_at' => 'mañana'], $idempotent()), 'POST', '/portal/appointments/{appointment}/reschedule');
    $check($this->postJson("{$portalAppointmentPath}/cancel", ['cancel_detail' => 'Viaje']), 'POST', '/portal/appointments/{appointment}/cancel');
    $check($this->postJson("{$portalAppointmentPath}/cancel"), 'POST', '/portal/appointments/{appointment}/cancel');

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
