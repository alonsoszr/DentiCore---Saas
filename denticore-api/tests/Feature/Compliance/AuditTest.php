<?php

/*
 * Bitácora de auditoría (TASK-011; SDD §2.12, §5.14; CUS-65, RN-67, RF-186, DD-46,
 * DI-11, DI-17, RNF-114). T-149 y T-150 de SDD §6.3.9.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function auditActions(): array
{
    return AuditLog::query()->orderBy('id')->pluck('action')->all();
}

it('writes one audit row for each auditable event type without clinical values', function () {
    $superAdmin = User::factory()->superAdmin()->create(['email' => 'sa@denticore.test']);

    // tenant.created
    $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/tenants', [
        'name' => 'Clínica Sonrisa',
        'slug' => 'clinica-sonrisa',
        'subscription_plan' => 'pro',
        'admin' => ['name' => 'Ana Admin', 'email' => 'ana@sonrisa.test', 'password' => 'password123'],
    ])->assertCreated();
    $tenant = Tenant::query()->where('slug', 'clinica-sonrisa')->sole();
    $admin = User::query()->where('email', 'ana@sonrisa.test')->sole();

    // auth.login_failed y auth.login_ok
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-sonrisa', 'email' => 'ana@sonrisa.test', 'password' => 'mala-clave'])
        ->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-sonrisa', 'email' => 'ana@sonrisa.test', 'password' => 'password123'])
        ->assertOk();

    // user.created, user.updated, user.role_changed, user.deactivated, user.reactivated
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
        'name' => 'Diego Dentista', 'email' => 'diego@sonrisa.test', 'password' => 'password123', 'role' => 'dentist',
    ])->assertCreated();
    $dentist = User::query()->where('email', 'diego@sonrisa.test')->sole();
    $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$dentist->uuid}", ['name' => 'Diego D.'])->assertOk();
    $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$dentist->uuid}", ['role' => 'receptionist'])->assertOk();
    $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$dentist->uuid}", ['is_active' => false])->assertOk();
    $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$dentist->uuid}", ['is_active' => true])->assertOk();

    // patient.created y clinical_record.viewed
    $patientUuid = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/patients', [
        'document_id' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
        'birth_date' => '1990-05-10', 'phone' => '987654321', 'email' => 'rosa@correo.test',
        'medical_history' => ['alergias' => ['Penicilina']],
    ])->assertCreated()->json('data.id');
    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/patients/{$patientUuid}")->assertOk();

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
