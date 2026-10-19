<?php

/* TASK-106; SDD §1.9, §5.12, T-151; CUS-69, RF-190, RN-68, RNF-002, RNF-159. */

use App\Modules\Compliance\Jobs\ApplyRetentionPolicyJob;
use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Scheduling\ScheduledTaskRun;
use App\Support\Tenancy\MissingTenantContextException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'America/Lima'));
});

it('moves patients to passive at 5 years and to eligible at 20 years', function (string $status, int $years, int $seconds, string $expected) {
    $tenant = Tenant::factory()->create();
    $mergedInto = $status === 'fusionado' ? Patient::factory()->for($tenant)->create()->id : null;
    $patient = Patient::factory()->for($tenant)->create([
        'archive_status' => $status,
        'merged_into_patient_id' => $mergedInto,
        'last_attention_at' => now()->subYearsNoOverflow($years)->subSeconds($seconds),
    ]);

    $this->artisan('retention:apply')->assertSuccessful();

    TenantContext::run($tenant, function () use ($patient, $expected, $mergedInto) {
        expect($patient->fresh()->archive_status)->toBe($expected)
            ->and(Patient::query()->count())->toBe($mergedInto === null ? 1 : 2);
    });
})->with([
    'antes de cinco años' => ['activo', 5, -1, 'activo'],
    'exactamente cinco años' => ['activo', 5, 0, 'activo'],
    'más de cinco años' => ['activo', 5, 1, 'pasivo'],
    'antes de veinte años' => ['pasivo', 20, -1, 'pasivo'],
    'exactamente veinte años' => ['pasivo', 20, 0, 'pasivo'],
    'más de veinte años' => ['pasivo', 20, 1, 'apto_eliminacion'],
    'bloqueado antes de veinte años' => ['bloqueado', 20, -1, 'bloqueado'],
    'bloqueado con veinte años cumplidos' => ['bloqueado', 20, 1, 'apto_eliminacion'],
    'activo con más de veinte años' => ['activo', 20, 1, 'apto_eliminacion'],
    'fusionado no cambia' => ['fusionado', 25, 1, 'fusionado'],
    'apto no se elimina' => ['apto_eliminacion', 25, 1, 'apto_eliminacion'],
])->group('T-151', 'RN-68', 'RF-190', 'RNF-002', 'CUS-69', 'TASK-106');

it('uses creation for patients without attentions and calendar years at date boundaries', function (string $date) {
    $this->travelTo(Carbon::parse($date, 'America/Lima'));
    $tenant = Tenant::factory()->create();
    $old = Patient::factory()->for($tenant)->create([
        'created_at' => now()->subYearsNoOverflow(5)->subSecond(), 'last_attention_at' => null,
    ]);
    $boundary = Patient::factory()->for($tenant)->create([
        'created_at' => now()->subYearsNoOverflow(5), 'last_attention_at' => null,
    ]);
    $recentAttention = Patient::factory()->for($tenant)->create([
        'created_at' => now()->subYearsNoOverflow(25), 'last_attention_at' => now()->subYear(),
    ]);

    $this->artisan('retention:apply')->assertSuccessful();

    TenantContext::run($tenant, fn () => expect($old->fresh()->archive_status)->toBe('pasivo')
        ->and($boundary->fresh()->archive_status)->toBe('activo')
        ->and($recentAttention->fresh()->archive_status)->toBe('activo'));
})->with(['2028-02-29 00:00:00', '2027-01-01 00:00:00', '2026-10-31 23:59:59'])
    ->group('T-151', 'RN-68', 'RF-190', 'RNF-002', 'CUS-69', 'TASK-106');

it('processes active and suspended clinics independently and records successful runs', function () {
    foreach (['activa', 'suspendida', 'cancelada', 'eliminada'] as $status) {
        $tenant = Tenant::factory()->create(['status' => $status]);
        $patient = Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);
        $this->artisan('retention:apply')->assertSuccessful();

        $processed = in_array($status, ['activa', 'suspendida'], true);
        TenantContext::run($tenant, fn () => expect($patient->fresh()->archive_status)->toBe($processed ? 'pasivo' : 'activo'));
        expect(ScheduledTaskRun::query()->where('task', 'retention:apply')->where('tenant_id', $tenant->id)->exists())->toBe($processed);
    }
    expect(TenantContext::id())->toBeNull();
})->group('T-151', 'RN-68', 'RF-190', 'CUS-69', 'TASK-106');

it('audits transitions once without clinical values and changes no clinical rows', function () {
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);
    Attention::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'opened_at' => now()->subYears(6)]);
    $before = TenantContext::run($tenant, fn () => DB::table('attentions')->get()->all());

    $this->artisan('retention:apply')->assertSuccessful();
    $this->artisan('retention:apply')->assertSuccessful();

    TenantContext::run($tenant, function () use ($patient, $before) {
        $audit = AuditLog::query()->where('action', 'patient.archived')->sole();
        expect($audit->resource_uuid)->toBe($patient->uuid)
            ->and($audit->actor_role)->toBe('system')
            ->and($audit->changed_fields)->toBe(['archive_status'])
            ->and($audit->metadata)->toBe(['status' => 'pasivo'])
            ->and(DB::table('attentions')->get()->all())->toEqual($before);
    });
})->group('T-151', 'T-149', 'RN-68', 'RF-190', 'RNF-159', 'CUS-69', 'TASK-106');

it('rolls back archive changes audit and watermark together when auditing fails', function () {
    $tenant = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);
    $audit = Mockery::mock(AuditLogger::class)->makePartial();
    $audit->shouldReceive('record')->withArgs(fn (AuditEvent $event) => $event === AuditEvent::PatientArchived)
        ->once()->passthru();
    $audit->shouldReceive('record')->withArgs(fn (AuditEvent $event) => $event === AuditEvent::PatientArchived)
        ->andThrow(new RuntimeException('Fallo sintético de auditoría'));
    Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);
    $this->app->instance(AuditLogger::class, $audit);

    expect(fn () => $this->artisan('retention:apply')->run())->toThrow(RuntimeException::class, 'Fallo sintético');

    TenantContext::run($tenant, fn () => expect($patient->fresh()->archive_status)->toBe('activo')
        ->and(Patient::query()->where('archive_status', 'pasivo')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'patient.archived')->count())->toBe(0));
    expect(ScheduledTaskRun::query()->where('task', 'retention:apply')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
})->group('T-151', 'T-157', 'RN-68', 'RF-190', 'CUS-69', 'TASK-106');

it('keeps passive patients out of default lists and reactivates them with a new attention', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    $patient = Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);

    $this->artisan('retention:apply')->assertSuccessful();
    $this->getJson('/api/v1/patients')->assertOk()->assertJsonMissing(['id' => $patient->uuid]);
    $this->getJson('/api/v1/patients?archive_status=pasivo')->assertOk()->assertJsonPath('data.0.id', $patient->uuid);
    $this->postJson("/api/v1/patients/{$patient->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    TenantContext::run($tenant, fn () => expect($patient->fresh()->archive_status)->toBe('activo'));
    $this->getJson('/api/v1/patients')->assertOk()->assertJsonPath('data.0.id', $patient->uuid);
})->group('T-151', 'RN-68', 'RF-190', 'CUS-69', 'TASK-106');

it('registers retention at 02:00 with single server and overlap protection', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'retention:apply'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
})->group('T-151', 'RF-190', 'CUS-69', 'TASK-106');

it('limits each retention job to its clinic and rejects a job without a tenant', function () {
    $tenant = Tenant::factory()->create();
    $foreign = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create(['last_attention_at' => now()->subYears(6)]);
    $other = Patient::factory()->for($foreign)->create(['last_attention_at' => now()->subYears(6)]);

    ApplyRetentionPolicyJob::dispatchSync($tenant->id);

    TenantContext::run($tenant, fn () => expect($patient->fresh()->archive_status)->toBe('pasivo'));
    TenantContext::run($foreign, fn () => expect($other->fresh()->archive_status)->toBe('activo'));
    expect(fn () => ApplyRetentionPolicyJob::dispatchSync(null))->toThrow(MissingTenantContextException::class);
    expect(TenantContext::id())->toBeNull();
})->group('T-151', 'RN-01', 'RF-190', 'CUS-69', 'TASK-106');

it('processes the reference patient volume in bounded batches within the 30 minute window', function () {
    // SRS §13.2.2: 49 clínicas con 2000 fichas y una con 20000. Se reproduce el volumen
    // de patients que consulta retención, no las demás tablas del CDR ni el entorno ER.
    $tenants = Tenant::factory()->count(50)->create();
    foreach ($tenants as $index => $tenant) {
        $count = $index === 49 ? 20000 : 2000;
        $author = User::factory()->for($tenant)->create(['role' => 'receptionist']);
        TenantContext::run($tenant, fn () => DB::statement(<<<'SQL'
            INSERT INTO patients (uuid, tenant_id, first_name, last_name, search_name, birth_date, sex,
                                  document_type, document_number, document_hash, clinical_record_number,
                                  clinical_record_hash, archive_status, created_by, last_attention_at, created_at)
            SELECT gen_random_uuid(), ?, 'Paciente sintético', 'Retención ' || g, 'paciente sintetico retencion ' || g, DATE '1990-01-01', 'femenino',
                   'dni', 'v1:sintetico', encode(sha256(('document-' || g)::bytea), 'hex'), 'v1:sintetico',
                   encode(sha256(('hc-' || g)::bytea), 'hex'),
                   CASE WHEN g % 2 = 0 THEN 'activo' ELSE 'bloqueado' END, ?, ?::timestamptz, ?::timestamptz
              FROM generate_series(1, ?) AS g
            SQL, [$tenant->id, $author->id, now()->subYears(21), now()->subYears(21), $count]));
    }

    $started = hrtime(true);
    $this->artisan('retention:apply')->assertSuccessful();
    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    expect($seconds)->toBeLessThan(1800);
    foreach ($tenants as $index => $tenant) {
        TenantContext::run($tenant, fn () => expect(Patient::query()->where('archive_status', 'apto_eliminacion')->count())
            ->toBe($index === 49 ? 20000 : 2000));
    }
    expect(ScheduledTaskRun::query()->where('task', 'retention:apply')->count())->toBe(50);
})->group('T-151', 'RF-190', 'CUS-69', 'performance', 'TASK-106');

it('has no registered deletion route for retained clinical records', function () {
    $deletions = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route) => $route->uri())->sort()->values()->all();

    // El SDD permite quitar diagnósticos de notas abiertas, borradores y recursos no clínicos.
    // La lista cerrada detecta cualquier nueva superficie DELETE, incluida CUS-70 (MS-14).
    expect($deletions)->toBe([
        'api/v1/attentions/{attention}/diagnoses/{diagnosis}',
        'api/v1/auth/2fa',
        'api/v1/budgets/{budget}',
        'api/v1/plan-items/{item}',
        'api/v1/procedures/{procedure}',
        'api/v1/schedule-blocks/{block}',
    ]);
})->group('RNF-159', 'RN-68', 'CUS-69', 'TASK-106');

it('rejects physical deletion of a closed diagnosis a non draft plan item and an issued budget', function () {
    $tenant = Tenant::factory()->create();
    $dentist = $this->actingAsRole('dentist', $tenant);
    $patient = Patient::factory()->for($tenant)->create();
    [$attention, $diagnosisId, $item, $budget] = TenantContext::run($tenant, function () use ($tenant, $dentist, $patient) {
        $attention = Attention::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'dentist_id' => $dentist->id,
        ]);
        $diagnosisId = DB::table('attention_diagnoses')->insertGetId([
            'tenant_id' => $tenant->id, 'attention_id' => $attention->id, 'cie10_code' => 'K02.1',
            'type' => 'definitivo', 'origin' => 'nota', 'created_by' => $dentist->id,
        ]);
        $attention->forceFill(['status' => 'cerrada_incompleta', 'closed_at' => now(), 'closed_by_system' => true])->save();
        $plan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto',
        ]);
        $item = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id]);
        $budget = Budget::factory()->create([
            'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'emitido',
            'number' => 'P-000001', 'issued_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        return [$attention, $diagnosisId, $item, $budget];
    });

    $diagnosisUuid = TenantContext::run($tenant, fn () => DB::table('attention_diagnoses')->where('id', $diagnosisId)->value('uuid'));
    $this->deleteJson("/api/v1/attentions/{$attention->uuid}/diagnoses/{$diagnosisUuid}")->assertConflict();
    $this->deleteJson("/api/v1/plan-items/{$item->uuid}")->assertConflict();
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}")->assertConflict();

    TenantContext::run($tenant, fn () => expect(DB::table('attention_diagnoses')->where('id', $diagnosisId)->exists())->toBeTrue()
        ->and($item->fresh())->not->toBeNull()->and($budget->fresh())->not->toBeNull());
})->group('RNF-159', 'RN-68', 'CUS-69', 'TASK-106');
