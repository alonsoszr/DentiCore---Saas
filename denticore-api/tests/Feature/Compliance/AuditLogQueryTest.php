<?php

/*
 * Consulta y exportación de la bitácora (TASK-105; CUS-66; SDD §2.12, §4.3.11; RF-187, RN-04,
 * RN-67, RNF-024).
 */

use App\Modules\Compliance\Services\AuditLogSearch;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\RucValidator;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evento de la bitácora de una clínica (o de la plataforma, sin clínica) en la hora local de Lima
 * indicada. AuditLogger deja `created_at` a la BD (`now()`), que no sigue el reloj simulado: la
 * fila se inserta con su instante; la cadena de hashes la sigue calculando el disparador.
 *
 * @param  array<string, scalar|null>  $meta
 */
function auditAt(?Tenant $tenant, string $at, AuditEvent $event, ?Model $resource, ?User $actor, array $meta = []): void
{
    $insert = fn () => DB::table('audit_logs')->insert([
        'tenant_id' => $tenant?->id,
        'user_id' => $actor?->id,
        'actor_role' => $actor->role ?? 'system',
        'action' => $event->value,
        'resource_type' => $resource ? Str::snake(class_basename($resource)) : null,
        'resource_uuid' => $resource?->getAttribute('uuid'),
        'patient_uuid' => $resource instanceof Patient ? $resource->uuid : null,
        'metadata' => $meta === [] ? null : json_encode($meta),
        'prev_hash' => '',
        'hash' => '',
        'created_at' => Carbon::parse($at, 'America/Lima')->utc(),
    ]);
    $tenant === null ? $insert() : TenantContext::run($tenant, $insert);
}

/**
 * Dos clínicas con su administrador, un paciente y eventos en tres días distintos, más un evento de
 * plataforma (sin clínica).
 *
 * @return array{own: Tenant, other: Tenant, admin: User, receptionist: User, patient: Patient}
 */
function auditScenario(): array
{
    [$own, $other] = Tenant::factory()->count(2)->create(['timezone' => 'America/Lima']);
    $admin = User::factory()->for($own)->create(['role' => 'clinic_admin', 'name' => 'Ana Torres']);
    $receptionist = User::factory()->for($own)->create(['role' => 'receptionist', 'name' => 'Rita Huamán']);
    $foreignAdmin = User::factory()->for($other)->create(['role' => 'clinic_admin']);
    $patient = TenantContext::run($own, fn () => Patient::factory()->for($own)->create());
    $foreignPatient = TenantContext::run($other, fn () => Patient::factory()->for($other)->create());

    auditAt($own, '2026-11-02 09:00', AuditEvent::PatientCreated, $patient, $receptionist);
    auditAt($own, '2026-11-03 10:00', AuditEvent::PatientIdentityUpdated, $patient, $receptionist);
    auditAt($own, '2026-11-04 23:30', AuditEvent::AuthLoginOk, $admin, $admin);
    auditAt($other, '2026-11-03 11:00', AuditEvent::PatientCreated, $foreignPatient, $foreignAdmin);
    auditAt(null, '2026-11-03 12:00', AuditEvent::TenantSuspended, $other, null, ['reason_code' => 'pago']);
    test()->travelTo(Carbon::parse('2026-11-10 09:00', 'America/Lima'));

    return ['own' => $own, 'other' => $other, 'admin' => $admin, 'receptionist' => $receptionist, 'patient' => $patient];
}

it('shows a clinic admin only the rows of their clinic', function () {
    ['own' => $own, 'admin' => $admin, 'patient' => $patient] = auditScenario();
    $this->actingWithToken($admin);

    // RF-187, RN-04: ni la otra clínica ni los eventos de plataforma; lo más reciente primero.
    $rows = $this->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.action', 'auth.login_ok')
        ->assertJsonPath('data.0.user.name', 'Ana Torres')
        ->assertJsonPath('data.2.action', 'patient.created')
        ->assertJsonPath('data.2.patient_id', $patient->uuid)
        ->assertJsonPath('data.2.resource_type', 'patient')
        ->assertJsonPath('data.2.resource_id', $patient->uuid)
        ->json('data');

    expect(collect($rows)->pluck('id')->all())
        ->toBe(TenantContext::run($own, fn () => AuditLog::query()->where('tenant_id', $own->id)->orderByDesc('created_at')->pluck('uuid')->all()))
        ->and($rows[0])->not->toHaveKey('clinic');
})->group('RF-187', 'RN-04', 'CUS-66');

it('filters the log by local date, user, action and resource', function () {
    ['admin' => $admin, 'receptionist' => $receptionist, 'patient' => $patient] = auditScenario();
    $this->actingWithToken($admin);

    // Las 23:30 del 4 en Lima son el 5 en UTC: el filtro usa la fecha local de la clínica.
    $this->getJson('/api/v1/audit-logs?from=2026-11-04&to=2026-11-04')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'auth.login_ok');
    $this->getJson("/api/v1/audit-logs?user_id={$receptionist->uuid}")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/audit-logs?action=patient.identity_updated')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/audit-logs?resource_type=patient&resource_id={$patient->uuid}&from=2026-11-03")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'patient.identity_updated');
    $this->getJson('/api/v1/audit-logs?user_id='.Str::uuid())->assertOk()->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/audit-logs?action=otra.accion&from=2026-11-05&to=2026-11-04')
        ->assertUnprocessable()->assertJsonValidationErrors(['action', 'to']);
})->group('RF-187', 'CUS-66');

it('shows the super admin every clinic and the platform events without the patient', function () {
    ['own' => $own, 'other' => $other, 'patient' => $patient] = auditScenario();
    $this->actingAsRole('super_admin');

    $rows = $this->getJson('/api/v1/platform/audit-logs')->assertOk()->assertJsonCount(5, 'data')->json('data');
    $platform = collect($rows)->firstWhere('action', 'tenant.suspended');

    // RN-04: metadatos sin datos que identifiquen al paciente.
    expect($platform['clinic'])->toBeNull()
        ->and($platform['metadata'])->toBe(['reason_code' => 'pago'])
        ->and(collect($rows)->firstWhere('resource_id', $patient->uuid)['clinic'])->toBe(['id' => $own->uuid, 'name' => $own->name])
        ->and(collect($rows)->every(fn (array $row) => ! array_key_exists('patient_id', $row)))->toBeTrue();

    $this->getJson("/api/v1/platform/audit-logs?clinic_id={$other->uuid}")->assertOk()->assertJsonCount(1, 'data');
})->group('RF-187', 'RN-04', 'CUS-66');

it('exports the clinic log to CSV and records its own audit event', function () {
    ['own' => $own, 'admin' => $admin, 'receptionist' => $receptionist] = auditScenario();
    $this->actingWithToken($admin);

    $csv = $this->post('/api/v1/audit-logs/exports', ['from' => '2026-11-01', 'to' => '2026-11-03', 'user_id' => $receptionist->uuid])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('bitacora-2026-11-01-2026-11-03.csv')
        ->streamedContent();
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toStartWith("\u{FEFF}fecha,usuario_id,usuario,rol,accion")
        ->and($lines[0])->toContain('paciente_id')
        ->and($lines[1])->toContain('patient.created')->toContain('Rita Huamán')
        ->and($lines[2])->toContain('patient.identity_updated');

    // CUS-66: la exportación deja su propio evento, solo con el rango.
    $exported = TenantContext::run($own, fn () => AuditLog::query()->where('action', 'audit.exported')->sole());
    expect($exported->user_id)->toBe($admin->id)
        ->and($exported->metadata)->toEqual(['scope' => 'clinica', 'from' => '2026-11-01', 'to' => '2026-11-03', 'rows' => 2]);
})->group('RF-187', 'RN-67', 'CUS-66');

it('exports the platform log with the clinic and without the patient', function () {
    ['own' => $own] = auditScenario();
    $superAdmin = $this->actingAsRole('super_admin');

    $csv = $this->post('/api/v1/platform/audit-logs/exports', ['from' => '2026-11-03', 'to' => '2026-11-03'])
        ->assertOk()
        ->assertDownload('bitacora-plataforma-2026-11-03-2026-11-03.csv')
        ->streamedContent();
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toContain('clinica_id,clinica')
        ->and($lines[0])->not->toContain('paciente_id')
        ->and($csv)->toContain($own->name)->toContain('tenant.suspended');

    $exported = AuditLog::query()->where('action', 'audit.exported')->sole();
    expect($exported->tenant_id)->toBeNull()
        ->and($exported->user_id)->toBe($superAdmin->id)
        ->and($exported->metadata)->toEqual(['scope' => 'plataforma', 'from' => '2026-11-03', 'to' => '2026-11-03', 'rows' => 3]);
})->group('RF-187', 'RN-04', 'RN-67', 'CUS-66');

it('limits each export to a range of 31 days', function () {
    ['admin' => $admin] = auditScenario();
    $this->actingWithToken($admin);

    $this->postJson('/api/v1/audit-logs/exports', [])->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->postJson('/api/v1/audit-logs/exports', ['from' => '2026-10-01', 'to' => '2026-11-02'])
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->post('/api/v1/audit-logs/exports', ['from' => '2026-10-03', 'to' => '2026-11-02'])->assertOk();
})->group('RF-187', 'CUS-66');

it('neutralizes spreadsheet formulas in the exported cells', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'name' => '=HYPERLINK("http://x.test")']);
    auditAt($tenant, '2026-11-02 09:00', AuditEvent::AuthLoginOk, $admin, $admin);
    $this->travelTo(Carbon::parse('2026-11-10 09:00', 'America/Lima'));
    $this->actingWithToken($admin);

    $csv = $this->post('/api/v1/audit-logs/exports', ['from' => '2026-11-01', 'to' => '2026-11-10'])->assertOk()->streamedContent();

    expect($csv)->toContain("\"'=HYPERLINK(\"\"http://x.test\"\")\"")->not->toContain(',=HYPERLINK');
})->group('RF-187', 'RNF-098');

it('filters by date and user through the index of the clinic, user and date', function () {
    // RNF-024 (12 meses de eventos): con volumen y estadísticas reales, el filtro por fecha y
    // usuario recorre el índice (tenant_id, user_id, created_at) de la partición, no la tabla.
    // Como en PatientSearchTest, una transacción del propietario del esquema que se revierte.
    $user = User::factory()->for(Tenant::factory())->create(['role' => 'receptionist']);
    $owner = DB::connection('pgsql_migrator');
    $owner->beginTransaction();

    try {
        $rucBody = '2099999903';
        $tenantId = $owner->table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Clínica Bitácora', 'legal_name' => 'Clínica Bitácora S.A.C.',
            'ruc' => $rucBody.RucValidator::checkDigit($rucBody), 'address' => 'Av. Sintética 123, Lima',
            'slug' => 'clinica-bitacora-'.Str::lower(Str::random(8)),
            'subscription_plan_id' => $owner->table('subscription_plans')->where('code', 'pro')->value('id'),
            'status' => 'activa', 'created_at' => now(),
        ]);
        $owner->unprepared(<<<SQL
            INSERT INTO audit_logs (uuid, tenant_id, user_id, actor_role, action, prev_hash, hash, created_at)
            SELECT gen_random_uuid(), {$tenantId}, CASE WHEN g % 50 = 0 THEN {$user->id} ELSE {$user->id} + g END, 'receptionist',
                   'auth.login_ok', '', '', TIMESTAMPTZ '2026-01-01 00:00:00+00' + (g || ' minutes')::interval
              FROM generate_series(1, 20000) AS g;
            ANALYZE audit_logs;
            SQL);

        $query = app(AuditLogSearch::class)->query($tenantId, [
            'from' => '2026-01-05', 'to' => '2026-01-06', 'user_id' => $user->uuid,
        ]);
        $plan = collect($owner->select('EXPLAIN '.$query->toRawSql()))->pluck('QUERY PLAN')->implode("\n");

        expect($plan)->toContain('tenant_id_user_id_created_at')
            ->and($plan)->not->toContain('Seq Scan');
    } finally {
        $owner->rollBack();
    }
})->group('RNF-024', 'RF-187', 'CUS-66');
