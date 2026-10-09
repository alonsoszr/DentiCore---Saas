<?php

/*
 * Bitácora de auditoría (TASK-011; SDD §2.12, §5.14; CUS-65, RN-67, RF-186, DD-46,
 * DI-11, DI-17, RNF-114). T-149 y T-150 de SDD §6.3.9.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ClinicalFixtures;

function auditActions(): array
{
    return AuditLog::query()->orderBy('id')->pluck('action')->all();
}

it('writes one audit row for each auditable event type without clinical values', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => 'sa@denticore.test']);

    // tenant.created
    $this->actingWithToken($superAdmin)->postJson('/api/v1/platform/tenants', [
        'name' => 'Clínica Sonrisa',
        'legal_name' => 'Clínica Sonrisa S.A.C.',
        'ruc' => '20600000013',
        'slug' => 'clinica-sonrisa',
        'address' => 'Av. Arequipa 1234, Lima',
        'subscription_plan' => 'pro',
        'admin' => ['name' => 'Ana Admin', 'email' => 'ana@sonrisa.test'],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
    $admin = User::query()->where('email', 'ana@sonrisa.test')->sole();
    // La activación por invitación es de TASK-029: aquí se simula que el administrador ya la aceptó.
    $admin->forceFill(['password' => 'password123', 'status' => 'activo'])->save();

    // auth.login_failed y auth.login_ok
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-sonrisa', 'email' => 'ana@sonrisa.test', 'password' => 'mala-clave'])
        ->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-sonrisa', 'email' => 'ana@sonrisa.test', 'password' => 'password123'])
        ->assertOk();

    // user.created, user.updated, user.role_changed, user.deactivated, user.reactivated
    $this->actingWithToken($admin)->postJson('/api/v1/users', [
        'name' => 'Diego Dentista', 'email' => 'diego@sonrisa.test', 'password' => 'password123', 'role' => 'dentist', 'cop_number' => '12345',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $dentist = User::query()->where('email', 'diego@sonrisa.test')->sole();
    $this->actingWithToken($admin)->patchJson("/api/v1/users/{$dentist->uuid}", ['name' => 'Diego D.'])->assertOk();
    $this->actingWithToken($admin)->patchJson("/api/v1/users/{$dentist->uuid}", ['role' => 'receptionist'])->assertOk();
    $this->actingWithToken($admin)->postJson("/api/v1/users/{$dentist->uuid}/deactivate")->assertOk();
    $this->actingWithToken($admin)->postJson("/api/v1/users/{$dentist->uuid}/reactivate")->assertOk();

    // patient.created y clinical_record.viewed
    $patientUuid = $this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
        'birth_date' => '1990-05-10', 'sex' => 'femenino', 'phone' => '987654321', 'email' => 'rosa@correo.test',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $this->actingWithToken($admin)->getJson("/api/v1/patients/{$patientUuid}")->assertOk();

    $rows = AuditLog::query()->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe([
        'tenant.created',
        'auth.login_failed',
        'auth.login_ok',
        'user.created',
        'user.updated',
        'user.role_changed',
        'user.deactivated',
        'user.reactivated',
        'patient.created',
        'clinical_record.viewed',
    ]);

    $byAction = $rows->keyBy('action');
    expect($byAction['tenant.created'])->tenant_id->toBeNull()->user_id->toBe($superAdmin->id)->actor_role->toBe('super_admin')
        ->and($byAction['auth.login_ok'])->tenant_id->toBe($tenant->id)->user_id->toBe($admin->id)
        ->and($byAction['user.updated']->changed_fields)->toBe(['name'])
        ->and($byAction['user.role_changed']->changed_fields)->toBe(['role'])
        ->and($byAction['patient.created'])->tenant_id->toBe($tenant->id)->patient_uuid->toBe($patientUuid)->resource_type->toBe('patient')
        ->and($byAction['clinical_record.viewed']->patient_uuid)->toBe($patientUuid)
        ->and($byAction['clinical_record.viewed']->ip_address)->toBe('127.0.0.1');

    // Ninguna fila contiene valores clínicos ni de identificación (RN-67).
    $serialized = json_encode(DB::table('audit_logs')->get());
    foreach (['45678912', '987654321', 'rosa@correo.test', 'Rosa', 'Quispe', 'Penicilina', 'ana@sonrisa.test', 'Diego', 'password'] as $value) {
        expect($serialized)->not->toContain($value);
    }
})->group('RN-67', 'RF-186', 'CUS-65', 'RNF-114');

it('rejects UPDATE and DELETE on audit_logs', function () {
    app(AuditLogger::class)->record(AuditEvent::TenantCreated, Tenant::factory()->create());

    $sqlStateOf = function (callable $statement): ?string {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return $exception->errorInfo[0] ?? null;
        }

        return null;
    };

    expect($sqlStateOf(fn () => DB::update("update audit_logs set action = 'x'")))->toBe('55000')
        ->and($sqlStateOf(fn () => DB::delete('delete from audit_logs')))->toBe('55000')
        ->and(auditActions())->toBe(['tenant.created']);
})->group('RN-67', 'RF-186');

it('chains the hashes of each clinic and of the platform separately', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $logger = app(AuditLogger::class);

    $logger->record(AuditEvent::TenantCreated, $tenantA);
    TenantContext::run($tenantA, fn () => $logger->record(AuditEvent::PatientCreated, Patient::factory()->for($tenantA)->create()));
    TenantContext::run($tenantB, fn () => $logger->record(AuditEvent::PatientCreated, Patient::factory()->for($tenantB)->create()));
    TenantContext::run($tenantA, fn () => $logger->record(AuditEvent::ClinicalRecordViewed));

    $rows = AuditLog::query()->orderBy('id')->get()->values();

    expect($rows[0]->prev_hash)->toBe(str_repeat('0', 64))
        ->and($rows[1]->prev_hash)->toBe(str_repeat('0', 64))
        ->and($rows[2]->prev_hash)->toBe(str_repeat('0', 64))
        ->and($rows[3]->prev_hash)->toBe($rows[1]->hash)
        ->and($rows->pluck('hash')->unique())->toHaveCount(4);
})->group('DD-46', 'DI-11', 'RNF-114');

it('refuses identification or clinical data in the metadata', function (string $key) {
    expect(fn () => app(AuditLogger::class)->record(AuditEvent::UserUpdated, null, [], [$key => 'x']))
        ->toThrow(LogicException::class);

    expect(auditActions())->toBe([]);
})->with(['document_number', 'phone', 'email', 'first_name', 'diagnosis', 'note'])->group('RN-67');

it('keeps partitions for the current year and the next two', function () {
    $year = (int) now()->format('Y');

    foreach ([$year, $year + 1, $year + 2] as $partitionYear) {
        expect(DB::selectOne('select to_regclass(?) is not null as present', ["audit_logs_y{$partitionYear}"])->present)
            ->toBeTrue("falta la partición audit_logs_y{$partitionYear}");
    }

    $this->artisan('partitions:ensure', ['--connection' => 'pgsql_migrator'])->assertSuccessful();
})->group('DI-17');

it('writes the audit events of the MS-01 flows without clinical values', function () {
    Storage::fake('s3');
    // El recorrido inicia sesión 6 veces desde la misma IP (throttle:login es de 5 por minuto).
    config(['auth.login_attempts_per_minute' => 100]);
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->plan('pro')->create(['slug' => 'clinica-hitos']);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'email' => 'ana@hitos.test', 'password' => 'Clave-Segura-2026', 'is_data_officer' => true]);
    // Nombre fijo: restablece su contraseña más abajo y la política rechaza las que contienen
    // palabras del nombre (un nombre aleatorio como «Eva» chocaría con «Nueva-Clave-2026»).
    $receptionist = User::factory()->for($tenant)->create(['role' => 'receptionist', 'name' => 'Rita Huamán', 'email' => 'rita@hitos.test', 'password' => 'Clave-Segura-2026']);

    // tenant.suspended, tenant.reactivated y tenant.plan_changed (CUS-02, CUS-03)
    $platform = $this->actingWithToken($superAdmin);
    $platform->postJson("/api/v1/platform/tenants/{$tenant->uuid}/suspend", ['reason' => 'Falta de pago'])->assertOk();
    $platform->postJson("/api/v1/platform/tenants/{$tenant->uuid}/reactivate", ['reason' => 'Pago regularizado'])->assertOk();
    $platform->putJson("/api/v1/platform/tenants/{$tenant->uuid}/plan", ['subscription_plan' => 'enterprise'])->assertOk();
    $this->app['auth']->forgetGuards();

    // auth.locked: 5 fallos seguidos (CA-06.2)
    $locked = User::factory()->for($tenant)->create(['role' => 'receptionist', 'email' => 'luis@hitos.test', 'password' => 'Clave-Segura-2026']);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-hitos', 'email' => $locked->email, 'password' => 'mala-clave'])
            ->assertUnauthorized();
    }

    // auth.2fa_configured (CUS-08) y auth.logout (CUS-10)
    $setupToken = $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-hitos', 'email' => $admin->email, 'password' => 'Clave-Segura-2026'])
        ->assertOk()->json('token');
    $secret = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/setup')->assertOk()->json('secret');
    $fullToken = $this->withToken($setupToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
        ->assertOk()->json('token');
    $this->withToken($fullToken)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withoutToken();

    // auth.password_changed (CUS-09)
    $this->postJson('/api/v1/auth/password/forgot', ['tenant_slug' => 'clinica-hitos', 'email' => $receptionist->email])->assertNoContent();
    $link = Notification::query()->where('event', 'restablecimiento_contrasena')->latest('id')->first()->payload['links']['reset'];
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => substr($link, strrpos($link, '/') + 1), 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
    ])->assertNoContent();

    // user.data_officer_changed (CUS-11)
    $clinic = $this->actingWithToken($admin);
    $otherAdmin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $clinic->patchJson("/api/v1/users/{$otherAdmin->uuid}", ['is_data_officer' => true])->assertOk();

    // patient.created, patient.identity_updated, representative.created y .ended, consent.granted (CUS-14 a CUS-17)
    $patientUuid = $clinic->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '71234567', 'first_name' => 'Lucía', 'last_name' => 'Quispe',
        'birth_date' => '2015-05-10', 'sex' => 'femenino', 'phone' => '987654321',
        'representative' => [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
            'relationship' => 'madre', 'phone' => '912345678', 'valid_from' => '2026-01-01',
        ],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $clinic->patchJson("/api/v1/patients/{$patientUuid}", ['phone' => '911222333'])->assertOk();
    $father = $clinic->postJson("/api/v1/patients/{$patientUuid}/representatives", [
        'document_type' => 'dni', 'document_number' => '42345678', 'first_name' => 'Luis', 'last_name' => 'Quispe',
        'relationship' => 'padre', 'phone' => '912345679', 'valid_from' => '2026-01-01',
    ])->assertCreated()->json('data.id');
    $clinic->postJson("/api/v1/patients/{$patientUuid}/representatives/{$father}/end", ['reason' => 'revocada'])->assertOk();
    $consentId = $clinic->postJson("/api/v1/patients/{$patientUuid}/consents", [
        'channel' => 'presencial', 'purpose_care' => true, 'confirmation_document_number' => '41234567',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

    // document.downloaded: la constancia PDF por su URL firmada (RF-066)
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();
    $url = $clinic->getJson("/api/v1/consents/{$consentId}/certificate")->assertOk()->json('data.url');
    $this->get($url)->assertOk();

    $actions = AuditLog::query()->pluck('action')->all();
    foreach ([
        'tenant.suspended', 'tenant.reactivated', 'tenant.plan_changed', 'auth.locked', 'auth.2fa_configured', 'auth.logout',
        'auth.password_changed', 'user.data_officer_changed', 'patient.created', 'patient.identity_updated',
        'representative.created', 'representative.ended', 'consent.granted', 'document.downloaded',
    ] as $action) {
        expect($actions)->toContain($action);
    }
    expect(AuditLog::query()->where('action', 'consent.granted')->sole()->patient_uuid)->toBe($patientUuid)
        ->and(AuditLog::query()->where('action', 'tenant.plan_changed')->sole()->changed_fields)->toBe(['subscription_plan_id']);

    // RN-67: sin documentos, teléfonos, nombres, correos, motivos ni contraseñas en la bitácora.
    $serialized = json_encode(DB::table('audit_logs')->get());
    foreach (['71234567', '41234567', '987654321', '911222333', 'Lucía', 'Quispe', 'Rosa', 'hitos.test', 'Falta de pago', 'Nueva-Clave-2026', $secret] as $value) {
        expect($serialized)->not->toContain($value);
    }
})->group('T-149', 'RN-67', 'RF-186', 'CUS-65');

it('writes the audit events of the MS-02 clinical flows without clinical values', function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);

    // attention.opened, note.saved, diagnosis.added, odontogram.entry_added y .entry_corrected,
    // attention.closed y clinical_record.viewed (CUS-21 a CUS-26, CUS-80)
    $attention = ClinicalFixtures::openAttention($tenant);
    $this->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor punzante al masticar'])->assertOk();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    $entry = ClinicalFixtures::recordFinding($attention, ['note' => 'Lesión cavitada profunda'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/odontogram-entries/{$entry}/corrections", [
        'kind' => 'anulacion', 'reason' => 'Registrada en el paciente equivocado',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->postJson("/api/v1/attentions/{$attention->uuid}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    $patientUuid = TenantContext::run($tenant, fn () => $attention->patient->uuid);
    $this->getJson("/api/v1/patients/{$patientUuid}/odontogram")->assertOk();

    // attention.auto_closed y addendum.added (CUS-27, CUS-81)
    $pending = ClinicalFixtures::openAttention($tenant);
    $this->travelTo(Carbon::parse('2026-10-06 23:59', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();
    $this->postJson("/api/v1/attentions/{$pending->uuid}/addenda", [
        'text' => 'Paciente refiere sangrado gingival', 'chief_complaint' => 'Sangrado de encías',
        'diagnoses' => [['cie10_code' => 'K05', 'type' => 'presuntivo']],
    ])->assertCreated();

    TenantContext::run($tenant, function () use ($patientUuid) {
        $actions = AuditLog::query()->pluck('action')->all();
        foreach ([
            'attention.opened', 'note.saved', 'diagnosis.added', 'odontogram.entry_added', 'odontogram.entry_corrected',
            'attention.closed', 'clinical_record.viewed', 'attention.auto_closed', 'addendum.added',
        ] as $action) {
            expect($actions)->toContain($action);
        }
        expect(AuditLog::query()->where('action', 'odontogram.entry_added')->sole()->patient_uuid)->toBe($patientUuid)
            ->and(AuditLog::query()->where('action', 'note.saved')->sole()->changed_fields)->toBe(['chief_complaint']);
    });

    // RN-67: sin textos clínicos, códigos CIE-10 ni motivos en la bitácora.
    $serialized = json_encode(DB::table('audit_logs')->get());
    foreach (['Dolor punzante', 'K02.1', 'K05', 'Lesión cavitada', 'paciente equivocado', 'sangrado gingival', 'Sangrado de encías'] as $value) {
        expect($serialized)->not->toContain($value);
    }
})->group('T-149', 'RN-67', 'RF-186', 'CUS-65');
