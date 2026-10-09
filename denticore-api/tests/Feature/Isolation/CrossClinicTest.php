<?php

/*
 * Aislamiento de las rutas, jobs, PDF y búsquedas de MS-01 a MS-03 (TASK-042, TASK-063; SDD §1.6,
 * §6.3; RN-01, RN-03, RF-003, RNF-101). T-015 y T-016.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Jobs\EndRepresentationsAtMajorityJob;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\InformedConsentService;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Jobs\ExpireBudgetsJob;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Modules\Treatment\Services\BudgetIssuer;
use App\Modules\Treatment\Services\BudgetService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ficha de un menor con su representante y su consentimiento en la clínica indicada.
 *
 * @return array{patient: Patient, representative: LegalRepresentative, consent: Consent}
 */
function foreignClinicRecord(Tenant $tenant, string $birthDate = '2015-01-01'): array
{
    return TenantContext::run($tenant, function () use ($tenant, $birthDate) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => $birthDate, 'last_name' => 'Núñez']);
        $representative = app(LegalRepresentativeService::class)->add($patient, [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
            'relationship' => 'madre', 'phone' => '912345678', 'email' => null, 'valid_from' => '2020-01-01',
        ]);
        $assistant = User::factory()->for($tenant)->create(['role' => 'receptionist']);
        $consent = app(ConsentService::class)->grant(
            $patient,
            ['channel' => 'presencial', 'confirmation_document_number' => '41234567'],
            $assistant,
            '127.0.0.1',
        );

        return ['patient' => $patient, 'representative' => $representative, 'consent' => $consent];
    });
}

/**
 * Registros comerciales de MS-03 en la clínica indicada: procedimiento, plan propuesto con su ítem,
 * presupuesto emitido (con su línea y su PDF pedido), plantilla de consentimiento informado y un
 * consentimiento firmado del ítem.
 *
 * @return array{procedure: Procedure, plan: TreatmentPlan, item: PlanItem, budget: Budget, template: string, informedConsent: InformedConsent}
 */
function foreignClinicTreatment(Tenant $tenant): array
{
    return TenantContext::run($tenant, function () use ($tenant): array {
        $patient = Patient::factory()->for($tenant)->create(['document_number' => '45678912', 'birth_date' => '1990-01-31']);
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'cop_number' => '12345']);
        $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true]);
        $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto']);
        $item = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id]);
        $budget = app(BudgetIssuer::class)->issue(app(BudgetService::class)->createDraft($plan, null), $dentist);
        $template = app(InformedConsentTemplateService::class)->create([
            'title' => 'Consentimiento de prueba', 'body' => 'El paciente {{paciente}} autoriza {{procedimiento}}.', 'procedures' => [$procedure->uuid],
        ], User::factory()->for($tenant)->create(['role' => 'clinic_admin']));
        $informedConsent = app(InformedConsentService::class)->sign($item, [
            'channel' => 'dispositivo', 'confirmation_document_number' => '45678912',
        ], $dentist, '127.0.0.1');

        return compact('procedure', 'plan', 'item', 'budget', 'informedConsent') + ['template' => $template->uuid];
    });
}

beforeEach(fn () => Storage::fake('s3'));

it('returns 404 for every route when the uuid belongs to another clinic', function () {
    // El recorrido hace más de 60 solicitudes por usuario (throttle:api); el límite real se prueba aparte.
    config(['auth.api_requests_per_minute' => 1000]);
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('clinic_admin', $own);
    $foreign = foreignClinicRecord($other);
    // Registros clínicos de la otra clínica: atención, hallazgo y diagnóstico (MS-02).
    [$foreignEntry, $foreignDiagnosis] = TenantContext::run($other, function () use ($other) {
        $entry = OdontogramEntry::factory()->create(['tenant_id' => $other->id]);

        return [$entry, $entry->attention->diagnoses()->forceCreate([
            'cie10_code' => 'K02.1', 'type' => 'definitivo', 'origin' => 'nota', 'created_by' => $entry->author_id,
        ])];
    });
    // Registros comerciales de la otra clínica (MS-03).
    $treatment = foreignClinicTreatment($other);
    [$foreignLine, $foreignDocument] = TenantContext::run($other, fn () => [
        $treatment['budget']->lines()->sole()->uuid,
        $treatment['budget']->pdfDocument->uuid,
    ]);
    $bindings = [
        '{procedure}' => $treatment['procedure']->uuid,
        '{plan}' => $treatment['plan']->uuid,
        '{item}' => $treatment['item']->uuid,
        '{budget}' => $treatment['budget']->uuid,
        '{line}' => $foreignLine,
        '{document}' => $foreignDocument,
        '{template}' => $treatment['template'],
        '{informedConsent}' => $treatment['informedConsent']->uuid,
        '{patient}' => $foreign['patient']->uuid,
        '{representative}' => $foreign['representative']->uuid,
        '{consent}' => $foreign['consent']->uuid,
        '{user}' => User::factory()->for($other)->create(['role' => 'receptionist'])->uuid,
        '{attention}' => $foreignEntry->attention->uuid,
        '{entry}' => $foreignEntry->uuid,
        '{diagnosis}' => $foreignDiagnosis->uuid,
        '{tooth}' => '16',
    ];

    // Toda ruta de la clínica que recibe el uuid de un registro (RF-003).
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/')
            && preg_match('/\{(patient|representative|consent|user|attention|entry|procedure|plan|item|budget|line|document|template|informedConsent)\}/', $route->uri()) === 1)
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, $route->uri()]))
        ->values();

    expect($routes->count())->toBeGreaterThanOrEqual(63);

    foreach ($routes as [$method, $uri]) {
        $path = '/'.strtr($uri, $bindings);
        $status = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

        expect($status)->toBe(404, "{$method} {$path} respondió {$status}");
    }
})->group('T-015', 'RN-03', 'RF-003', 'RNF-101');

it('searches and looks up patients only within their clinic', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('receptionist', $own);
    $mine = TenantContext::run($own, fn () => Patient::factory()->for($own)->create(['last_name' => 'Núñez']));
    $foreign = TenantContext::run($other, fn () => Patient::factory()->for($other)->create([
        'last_name' => 'Núñez', 'document_number' => '87654321',
    ]));

    expect($this->getJson('/api/v1/patients?q=nunez')->assertOk()->json('data.*.id'))->toBe([$mine->uuid]);
    $this->getJson('/api/v1/patients/lookup?document_type=dni&document_number=87654321')->assertNotFound();
    expect($foreign->tenant_id)->toBe($other->id);
})->group('T-016', 'RN-01', 'RF-054');

it('generates the consent certificate PDF inside the clinic of the consent', function () {
    $other = Tenant::factory()->create();
    $foreign = foreignClinicRecord($other);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    TenantContext::run($other, function () use ($foreign, $other) {
        $file = $foreign['consent']->fresh()->certificate->storedFile;

        expect($file->tenant_id)->toBe($other->id)
            ->and($file->path)->toStartWith("tenants/{$other->uuid}/")
            ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
    });
})->group('T-016', 'RF-066', 'RNF-101');

it('ends representations at majority only for the clinic of the job', function () {
    // Menores que cumplen 18 años mañana; el job corre ese día.
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00', 'America/Lima'));
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = foreignClinicRecord($own, '2008-10-07');
    $theirs = foreignClinicRecord($other, '2008-10-07');
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00', 'America/Lima'));

    dispatch_sync(new EndRepresentationsAtMajorityJob($own->id));

    expect(TenantContext::run($own, fn () => $mine['representative']->fresh()->ended_reason))->toBe('mayoria_de_edad')
        ->and(TenantContext::run($other, fn () => $theirs['representative']->fresh()->valid_until))->toBeNull();
})->group('T-016', 'RN-13', 'RNF-101');

it('generates the budget PDF inside the clinic of the budget', function () {
    $other = Tenant::factory()->create();
    $budget = foreignClinicTreatment($other)['budget'];

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    TenantContext::run($other, function () use ($budget, $other) {
        $document = $budget->fresh()->pdfDocument;
        $file = $document->storedFile;

        expect($document->status)->toBe('listo')
            ->and($file->tenant_id)->toBe($other->id)
            ->and($file->path)->toStartWith("tenants/{$other->uuid}/")
            ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
    });
})->group('T-016', 'RF-118', 'RNF-101');

it('expires budgets only for the clinic of the job', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = foreignClinicTreatment($own)['budget'];
    $theirs = foreignClinicTreatment($other)['budget'];
    Carbon::setTestNow(Carbon::parse('2026-11-06 09:00', 'America/Lima'));

    dispatch_sync(new ExpireBudgetsJob($own->id));

    expect(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('vencido')
        ->and(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('emitido');
})->group('T-016', 'RF-124', 'RNF-101');
