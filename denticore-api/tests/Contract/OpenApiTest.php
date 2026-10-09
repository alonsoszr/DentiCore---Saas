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
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Files\FileStorage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ClinicalFixtures;
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
