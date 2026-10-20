<?php

/*
 * Suspensión y reactivación de clínicas (TASK-024; CUS-02, SDD §5.13; RN-07, RF-006, RF-019).
 * T-011.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\InformedConsentService;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStorage;
use App\Support\Files\GeneratedDocument;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ArcoFixtures;

/**
 * Rutas registradas del grupo STAFF (las que pasan por `tenant.writable`).
 *
 * @return list<array{0: string, 1: string}>
 */
function staffRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('tenant.writable', $route->gatherMiddleware(), true))
        // `tenant.readonly_ok` (RN-70): documentos de lectura permitidos con la clínica suspendida;
        // cada uno lo prueba su tarea (p. ej. la copia de HC en ClinicalRecordCopyTest).
        ->reject(fn ($route) => in_array('tenant.readonly_ok', $route->gatherMiddleware(), true))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, $route->uri()]))
        ->values()
        ->all();
}

it('forbids writes in a suspended clinic and allows reads', function () {
    $tenant = Tenant::factory()->create(['status' => 'suspendida']);
    $this->actingAsRole('receptionist', $tenant);

    $this->getJson('/api/v1/patients')->assertOk();

    $this->postJson('/api/v1/patients', [
        'document_number' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe', 'birth_date' => '1990-05-10',
    ], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-07');

    expect(Patient::query()->withoutGlobalScopes()->count())->toBe(0);
})->group('CA-06.6', 'RN-07', 'RF-006');

it('applies the read-only rule to every registered staff route', function () {
    // El recorrido hace más de 60 solicitudes por usuario (throttle:api); el límite real se prueba aparte.
    config(['auth.api_requests_per_minute' => 1000]);
    $tenant = Tenant::factory()->create(['status' => 'suspendida']);
    $this->actingAsRole('clinic_admin', $tenant);

    // Piezas reales para las rutas de consentimiento informado (CUS-82/83): el parámetro inicial
    // de ruta debe existir; si no, el binding respondió 404 antes de llegar a la regla RN-07.
    $informedConsent = TenantContext::run($tenant, function () use ($tenant): array {
        $dentist = User::factory()->for($tenant)->create([
            'role' => 'dentist', 'status' => 'activo', 'cop_number' => '54321',
        ]);
        $patient = Patient::factory()->for($tenant)->create([
            'document_number' => '45678912', 'birth_date' => '1990-05-10',
        ]);
        $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true]);
        $plan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id,
        ]);
        $item = PlanItem::factory()->create([
            'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id, 'tooth' => 16,
        ]);

        $template = app(InformedConsentTemplateService::class)->create([
            'title' => 'Consentimiento de prueba',
            'body' => 'El paciente {{paciente}} autoriza el procedimiento {{procedimiento}} sobre la pieza {{pieza}}. '
                .'Riesgos: {{riesgos}}. Alternativas: {{alternativas}}. Informó: {{odontologo}}.',
            'procedures' => [$procedure->uuid],
        ], $dentist);

        $consent = app(InformedConsentService::class)->sign($item, [
            'channel' => 'dispositivo', 'confirmation_document_number' => '45678912',
        ], $dentist, '127.0.0.1');

        return ['template' => $template->uuid, 'item' => $item->uuid, 'consent' => $consent->uuid];
    });

    $attention = null;
    $budgetLine = null;
    $bindings = [
        '{user}' => fn () => User::factory()->for($tenant)->create(['role' => 'receptionist'])->uuid,
        '{patient}' => fn () => Patient::factory()->for($tenant)->create()->uuid,
        '{representative}' => fn () => (string) Str::uuid(),
        // Solicitud ARCO real: las rutas de atención (CUS-64) escriben y deben llegar a tenant.writable.
        '{arcoRequest}' => fn () => ArcoFixtures::request($tenant, Patient::factory()->for($tenant)->create())->uuid,
        // Consentimiento real: la ruta de revocación (CUS-18) escribe y debe llegar a tenant.writable.
        '{consent}' => fn () => TenantContext::run($tenant, function () use ($tenant) {
            $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31']);

            return app(ConsentService::class)->grant($patient, ['channel' => 'presencial', 'confirmation_document_number' => $patient->document_number],
                User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1')->uuid;
        }),
        '{template}' => fn () => $informedConsent['template'],
        '{item}' => fn () => $informedConsent['item'],
        '{informedConsent}' => fn () => $informedConsent['consent'],
        '{attention}' => function () use ($tenant, &$attention) {
            $attention = Attention::factory()->create(['tenant_id' => $tenant->id]);

            return $attention->uuid;
        },
        // Diagnóstico de la misma atención: el binding anidado lo resuelve antes de la regla RN-07.
        '{tooth}' => fn () => '16',
        '{procedure}' => fn () => Procedure::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{plan}' => fn () => TreatmentPlan::factory()->create(['tenant_id' => $tenant->id])->uuid,
        // Presupuesto con una línea suya: la ruta de la línea usa scopeBindings.
        '{budget}' => function () use ($tenant, &$budgetLine) {
            $budgetLine = BudgetLine::factory()->create(['tenant_id' => $tenant->id]);

            return TenantContext::run($tenant, fn () => $budgetLine->budget->uuid);
        },
        '{line}' => function () use (&$budgetLine) {
            return $budgetLine->uuid;
        },
        '{document}' => fn () => (string) Str::uuid(),
        '{dentist}' => fn () => User::factory()->for($tenant)->create(['role' => 'dentist'])->uuid,
        '{block}' => fn () => ScheduleBlock::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{type}' => fn () => AppointmentType::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{appointment}' => fn () => Appointment::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{set}' => fn () => RiskVariableSet::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{prediction}' => fn () => RiskPrediction::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{alert}' => fn () => RiskAlert::factory()->create(['tenant_id' => $tenant->id])->uuid,
        // Enlace de confirmación vigente de una cita de la clínica suspendida (ruta pública, RN-07).
        '{token}' => fn () => TenantContext::run($tenant, fn () => app(OneTimeTokenService::class)->issue(
            Appointment::factory()->create(['tenant_id' => $tenant->id]), TokenPurpose::AppointmentConfirmation, now()->addDay(),
        )['token']),
        '{entry}' => fn () => OdontogramEntry::factory()->create(['tenant_id' => $tenant->id])->uuid,
        '{diagnosis}' => function () use ($tenant, &$attention) {
            return TenantContext::run($tenant, fn () => $attention->diagnoses()->forceCreate([
                'cie10_code' => 'K02.1', 'type' => 'definitivo', 'origin' => 'nota', 'created_by' => $attention->dentist_id,
            ]))->uuid;
        },
    ];

    $routes = staffRoutes();
    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        $path = '/'.preg_replace_callback('/\{[a-z_]+\}/i', fn ($match) => $bindings[$match[0]](), $uri);
        $response = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()]);

        if (in_array($method, ['GET', 'OPTIONS'], true)) {
            expect($response->json('rule'))->not->toBe('RN-07', "{$method} {$path} se bloqueó por el estado de la clínica");
        } else {
            expect($response->status())->toBe(403, "{$method} {$path} respondió {$response->status()}")
                ->and($response->json('rule'))->toBe('RN-07');
        }
    }
})->group('RN-07', 'RF-006');

it('suspends a clinic with a reason and emails its administrators', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->for($tenant)->count(2)->create(['role' => 'clinic_admin']);
    User::factory()->for($tenant)->create(['role' => 'receptionist']);
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$tenant->uuid}/suspend", ['reason' => 'Falta de pago de octubre'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspendida')
        ->assertJsonPath('data.status_reason', 'Falta de pago de octubre');

    expect($tenant->fresh())->status->toBe('suspendida')->suspended_at->not->toBeNull();
    expect(Notification::query()->where('event', 'clinica_suspendida')->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'tenant.suspended')->sole()->resource_uuid)->toBe($tenant->uuid);
})->group('RF-019', 'CUS-02');

it('reactivates a suspended clinic with a reason', function () {
    $tenant = Tenant::factory()->create(['status' => 'suspendida']);
    User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$tenant->uuid}/reactivate", ['reason' => 'Pago regularizado'])
        ->assertOk()
        ->assertJsonPath('data.status', 'activa');

    expect($tenant->fresh())->status->toBe('activa')->suspended_at->toBeNull();
    expect(Notification::query()->where('event', 'clinica_reactivada')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'tenant.reactivated')->count())->toBe(1);
})->group('RF-019', 'CUS-02');

it('requires the reason and a valid current status', function () {
    $active = Tenant::factory()->create();
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$active->uuid}/suspend", ['reason' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/platform/tenants/{$active->uuid}/reactivate", ['reason' => 'No está suspendida'])
        ->assertStatus(409)->assertJsonPath('rule', 'RF-019');
})->group('RF-019');

it('limits each clinic to the requests per minute of its plan', function () {
    $tenant = Tenant::factory()->plan('basic')->create();
    $tenant->plan()->update(['rate_limit_per_minute' => 3]);
    $this->actingAsRole('receptionist', $tenant);

    foreach (range(1, 3) as $request) {
        $this->getJson('/api/v1/patients')->assertOk();
    }

    $this->getJson('/api/v1/patients')->assertStatus(429)->assertHeader('Retry-After');
})->group('RNF-042');

it('lets only the administrator of a cancelled clinic use the routes marked as exportable', function () {
    // Las marcas `tenant.exportable` y `tenant.readonly_ok` son de E2 (exportación y PDF); se prueban
    // sobre rutas de prueba con la misma cadena de middleware que el grupo STAFF.
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.writable', 'tenant.exportable'])
        ->post('/api/v1/_test/export', fn () => response()->json(['ok' => true]));
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.writable', 'tenant.readonly_ok'])
        ->post('/api/v1/_test/report', fn () => response()->json(['ok' => true]));
    $cancelled = Tenant::factory()->create(['status' => 'cancelada', 'cancelled_at' => now()]);

    $this->actingAsRole('clinic_admin', $cancelled);
    $this->postJson('/api/v1/_test/export')->assertOk();
    $this->getJson('/api/v1/patients')
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-07')
        ->assertJsonPath('detail', 'La clínica está cancelada: solo su administrador puede exportar sus datos.');

    $this->actingAsRole('receptionist', $cancelled);
    $this->postJson('/api/v1/_test/export')->assertForbidden()->assertJsonPath('rule', 'RN-07');

    $this->actingAsRole('receptionist', Tenant::factory()->create(['status' => 'suspendida']));
    $this->postJson('/api/v1/_test/report')->assertOk();
})->group('RN-07', 'RF-020', 'CUS-05');

it('blocks every staff route of a deleted clinic', function () {
    $this->actingAsRole('clinic_admin', Tenant::factory()->create(['status' => 'eliminada']));

    $this->getJson('/api/v1/patients')
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-07')
        ->assertJsonPath('detail', 'La clínica ya no está disponible.');
})->group('RN-07', 'RF-006');

it('answers 404 for the public page of a clinic that does not exist', function () {
    $this->getJson('/api/v1/public/clinics/no-existe')->assertNotFound();
});

/** Documento sintético: TASK-139 implementará la generación real del ZIP. */
function clinicExportFixture(Tenant $tenant, User $admin, bool $ready = false): GeneratedDocument
{
    return TenantContext::run($tenant, function () use ($tenant, $admin, $ready) {
        $document = GeneratedDocument::query()->create([
            'documentable_type' => $tenant->getMorphClass(), 'documentable_id' => $tenant->id,
            'kind' => 'exportacion_clinica', 'status' => 'pendiente', 'requested_by' => $admin->id,
        ]);
        if ($ready) {
            $file = app(FileStorage::class)->storeGenerated("PK\x05\x06".str_repeat("\0", 18), 'clinica.zip', 'application/zip');
            $document->forceFill(['status' => 'listo', 'stored_file_id' => $file->id, 'completed_at' => now()])->save();
        }

        return $document;
    });
}

it('cancels an active or suspended clinic with a reason timestamp audit and admin emails', function (string $status) {
    $this->freezeSecond();
    $tenant = Tenant::factory()->create(['status' => $status]);
    User::factory()->for($tenant)->count(2)->create(['role' => 'clinic_admin']);
    User::factory()->for($tenant)->create(['role' => 'receptionist']);
    $this->actingAsRole('super_admin');

    $this->postJson("/api/v1/platform/tenants/{$tenant->uuid}/cancel", ['reason' => 'Cierre definitivo de la clínica'])
        ->assertOk()->assertJsonPath('data.status', 'cancelada')
        ->assertJsonPath('data.status_reason', 'Cierre definitivo de la clínica');

    expect($tenant->fresh()->cancelled_at->equalTo(now()))->toBeTrue()
        ->and(Notification::query()->where('event', 'clinica_cancelada')->count())->toBe(2)
        ->and(OutboxMessage::query()->where('type', 'notification.send')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'tenant.cancelled')->sole()->resource_uuid)->toBe($tenant->uuid);
})->with(['activa', 'suspendida'])->group('T-012', 'RF-020', 'RN-07', 'CUS-02', 'TASK-107');

it('requires a cancellation reason and never restarts the 90 day window', function () {
    $this->freezeTime();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('super_admin');
    $uri = "/api/v1/platform/tenants/{$tenant->uuid}/cancel";

    $this->postJson($uri, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson($uri, ['reason' => 'Cierre definitivo'])->assertOk();
    $cancelledAt = $tenant->fresh()->cancelled_at;
    $this->travel(10)->days();
    $this->postJson($uri, ['reason' => 'Intento de ampliar el plazo'])->assertConflict();

    expect($tenant->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tenant.cancelled')->count())->toBe(1);
})->group('T-012', 'RF-020', 'RN-07', 'CUS-02', 'TASK-107');

it('allows only clinic_admin exports during 90 days after cancellation', function (int $offset, bool $allowed) {
    Storage::fake('s3');
    $this->freezeSecond();
    $cancelledAt = now()->copy();
    $tenant = Tenant::factory()->create(['status' => 'cancelada', 'cancelled_at' => $cancelledAt]);
    $admin = $this->actingAsRole('clinic_admin', $tenant);
    $document = clinicExportFixture($tenant, $admin, ready: true);
    $this->travelTo($cancelledAt->copy()->addSeconds($offset));

    $created = $this->postJson('/api/v1/clinic/exports', [], ['Idempotency-Key' => (string) Str::uuid()]);
    $shown = $this->getJson("/api/v1/clinic/exports/{$document->uuid}");
    if ($allowed) {
        $created->assertCreated()->assertJsonPath('data.kind', 'exportacion_clinica')->assertJsonPath('data.status', 'pendiente');
        $shown->assertOk()->assertJsonPath('data.status', 'listo');
    } else {
        $created->assertForbidden()->assertJsonPath('rule', 'RN-07');
        $shown->assertForbidden()->assertJsonPath('rule', 'RN-07');
    }
    $this->getJson('/api/v1/patients')->assertForbidden()->assertJsonPath('rule', 'RN-07');
})->with([
    'al cancelar' => [0, true],
    'antes del límite' => [90 * 86400 - 1, true],
    'exactamente 90 días' => [90 * 86400, true],
    'después del límite' => [90 * 86400 + 1, false],
    'día 91' => [91 * 86400, false],
])->group('T-012', 'RF-020', 'RN-07', 'RN-72', 'CUS-05', 'TASK-107');

it('denies export requests and documents to every non administrator role', function (string $role) {
    $this->freezeTime();
    $tenant = Tenant::factory()->create(['status' => 'cancelada', 'cancelled_at' => now()]);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $document = clinicExportFixture($tenant, $admin);
    $this->actingAsRole($role, $tenant);

    $this->postJson('/api/v1/clinic/exports', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    $this->getJson("/api/v1/clinic/exports/{$document->uuid}")->assertForbidden();
})->with(['super_admin', 'dentist', 'receptionist', 'patient'])->group('T-012', 'RF-020', 'RN-72', 'CUS-05', 'TASK-107');

it('requests exports idempotently without leaving outbox messages before TASK-139', function (string $status) {
    $this->freezeTime();
    $tenant = Tenant::factory()->create(['status' => $status]);
    $this->actingAsRole('clinic_admin', $tenant);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $first = $this->postJson('/api/v1/clinic/exports', [], $headers)->assertCreated()
        ->assertJsonPath('data.status', 'pendiente')->assertJsonPath('data.url', null);
    $this->postJson('/api/v1/clinic/exports', [], $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

    TenantContext::run($tenant, function () {
        expect(GeneratedDocument::query()->count())->toBe(1);
        expect(OutboxMessage::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'export.requested')->sole()->metadata)->toBe(['kind' => 'exportacion_clinica']);
    });
    // TASK-139 encolará estas solicitudes cuando exista el generador ZIP y su manejador.
    $this->getJson('/api/v1/clinic/exports/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.status', 'pendiente');
})->with(['activa', 'suspendida'])->group('T-012', 'RF-020', 'RN-72', 'CUS-05', 'TASK-107');

it('returns 404 for another clinic export and for documents of a different kind', function () {
    $tenant = Tenant::factory()->create();
    $admin = $this->actingAsRole('clinic_admin', $tenant);
    $foreign = Tenant::factory()->create();
    $other = clinicExportFixture($foreign, User::factory()->for($foreign)->create(['role' => 'clinic_admin']));
    $wrongKind = clinicExportFixture($tenant, $admin);
    TenantContext::run($tenant, fn () => $wrongKind->forceFill(['kind' => 'reporte'])->save());

    $this->getJson("/api/v1/clinic/exports/{$other->uuid}")->assertNotFound();
    $this->getJson("/api/v1/clinic/exports/{$wrongKind->uuid}")->assertNotFound();
})->group('T-012', 'RN-01', 'RF-020', 'RN-72', 'CUS-05', 'TASK-107');

it('expires signed export downloads at the cancellation boundary even if the URL is still valid', function () {
    Storage::fake('s3');
    $this->freezeTime();
    $tenant = Tenant::factory()->create(['status' => 'cancelada', 'cancelled_at' => now()->subDays(90)->addMinute()]);
    $admin = $this->actingAsRole('clinic_admin', $tenant);
    $document = clinicExportFixture($tenant, $admin, ready: true);
    $url = $this->getJson("/api/v1/clinic/exports/{$document->uuid}")->assertOk()->json('data.url');
    $this->get($url)->assertOk();
    $this->travel(61)->seconds();
    $this->get($url)->assertForbidden();
})->group('T-012', 'RF-020', 'RN-07', 'RN-72', 'CUS-05', 'TASK-107');

it('blocks old non export signed URLs after a clinic cancellation', function () {
    Storage::fake('s3');
    $this->freezeTime();
    $tenant = Tenant::factory()->create();
    $receptionist = $this->actingAsRole('receptionist', $tenant);
    $url = TenantContext::run($tenant, function () use ($receptionist) {
        $file = app(FileStorage::class)->storeGenerated('Documento sintético', 'reporte.txt', 'text/plain');

        return app(FileStorage::class)->temporaryUrl($file, $receptionist);
    });
    $tenant->forceFill(['status' => 'cancelada', 'cancelled_at' => now()])->save();

    $this->get($url)->assertForbidden();
})->group('T-012', 'RF-020', 'RN-07', 'CUS-05', 'TASK-107');

it('rejects cancelled clinic logins after the 90 day window', function () {
    $this->freezeTime();
    $tenant = Tenant::factory()->create(['status' => 'cancelada', 'cancelled_at' => now()->subDays(91)]);
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin', 'password' => 'clave-correcta-123']);

    $this->postJson('/api/v1/auth/login', ['tenant_slug' => $tenant->slug, 'email' => $admin->email, 'password' => 'clave-correcta-123'])
        ->assertUnauthorized()->assertJsonPath('detail', 'Credenciales inválidas.');
})->group('T-012', 'RF-020', 'RN-07', 'CUS-05', 'TASK-107');

it('rolls back an export request and outbox if audit fails', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->withArgs(fn (AuditEvent $event) => $event === AuditEvent::ExportRequested)
        ->andThrow(new RuntimeException('Fallo sintético'));
    $this->app->instance(AuditLogger::class, $audit);

    $this->postJson('/api/v1/clinic/exports', [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(500);

    TenantContext::run($tenant, fn () => expect(GeneratedDocument::query()->count())->toBe(0));
    expect(OutboxMessage::query()->where('queue', 'heavy')->count())->toBe(0);
})->group('T-012', 'T-157', 'RF-020', 'CUS-05', 'TASK-107');
