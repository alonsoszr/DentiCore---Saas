<?php

/*
 * Suspensión y reactivación de clínicas (TASK-024; CUS-02, SDD §5.13; RN-07, RF-006, RF-019).
 * T-011.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\InformedConsentService;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Rutas registradas del grupo STAFF (las que pasan por `tenant.writable`).
 *
 * @return list<array{0: string, 1: string}>
 */
function staffRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('tenant.writable', $route->gatherMiddleware(), true))
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
    $bindings = [
        '{user}' => fn () => User::factory()->for($tenant)->create(['role' => 'receptionist'])->uuid,
        '{patient}' => fn () => Patient::factory()->for($tenant)->create()->uuid,
        '{representative}' => fn () => (string) Str::uuid(),
        '{consent}' => fn () => (string) Str::uuid(),
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
