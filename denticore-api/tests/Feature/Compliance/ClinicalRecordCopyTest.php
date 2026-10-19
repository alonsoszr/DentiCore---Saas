<?php

/*
 * Copia de la historia clínica (TASK-102; CUS-62; SDD §4.3.10, §4.3.11; RF-180, RN-70). T-147.
 */

use App\Modules\Compliance\Documents\ClinicalRecordCopyRenderer;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Support\Audit\AuditLog;
use App\Support\Files\GeneratedDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;
use Tests\Support\PortalFixtures;

function copyKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

/**
 * Historia clínica con datos de cada módulo construido: atención con hallazgo, nota y diagnóstico
 * (M04), plan con presupuesto emitido (M05) y predicción de riesgo (M09).
 */
function patientWithFullRecord(Tenant $tenant, Patient $patient): void
{
    PortalFixtures::recordedFinding($tenant, $patient);
    $attention = TenantContext::run($tenant, fn () => Attention::query()->where('patient_id', $patient->id)->sole());
    test()->putJson("/api/v1/attentions/{$attention->uuid}/note", ['chief_complaint' => 'Dolor al masticar en la pieza 36'])->assertOk();
    test()->postJson("/api/v1/attentions/{$attention->uuid}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    PortalFixtures::issuedBudget($tenant, $patient);
    TenantContext::run($tenant, fn () => RiskPrediction::factory()->high()->create([
        'tenant_id' => $tenant->id,
        'risk_variable_set_id' => RiskVariableSet::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'locked_at' => now()])->id,
    ]));
}

it('generates the full clinical record copy from the portal', function () {
    Storage::fake('s3');
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    patientWithFullRecord($tenant, $patient);
    $this->actingWithToken($user);

    $requested = $this->postJson("/api/v1/portal/patients/{$patient->uuid}/clinical-record-copies", [], copyKey())
        ->assertCreated()
        ->assertJsonPath('data.kind', 'copia_hc')
        ->assertJsonPath('data.status', 'pendiente')
        ->assertJsonPath('data.url', null);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    $ready = $this->getJson('/api/v1/portal/documents/'.$requested->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.status', 'listo');
    expect($ready->json('data.url'))->toBeString();
    $this->get($ready->json('data.url'))->assertOk();

    $file = TenantContext::run($tenant, fn () => GeneratedDocument::query()->where('uuid', $requested->json('data.id'))->sole()->storedFile);
    expect(Storage::disk('s3')->get($file->path))->toStartWith('%PDF')
        ->and($file->original_name)->toStartWith('historia-clinica-');
})->group('T-147', 'RN-70', 'RF-180', 'CUS-62');

it('includes every section of RF-180 in the copy', function () {
    $tenant = Tenant::factory()->create();
    [, $patient] = PortalFixtures::holder($tenant);
    patientWithFullRecord($tenant, $patient);
    $this->actingAsRole('receptionist', $tenant);
    $id = $this->postJson("/api/v1/patients/{$patient->uuid}/clinical-record-copies", [], copyKey())->assertCreated()->json('data.id');

    $html = TenantContext::run($tenant, fn () => app(ClinicalRecordCopyRenderer::class)->html(GeneratedDocument::query()->where('uuid', $id)->sole()));

    foreach ([
        'Ficha del paciente', 'Consentimientos', 'Odontograma inicial', 'Odontograma vigente', 'Historial por pieza',
        'Atenciones', 'Planes de tratamiento', 'Presupuestos', 'Procedimientos realizados', 'Predicciones de riesgo de caries',
    ] as $section) {
        expect($html)->toContain($section);
    }
    expect($html)->toContain($patient->first_name)
        ->and($html)->toContain('Dolor al masticar en la pieza 36')
        ->and($html)->toContain('Lesión cavitada')
        ->and($html)->toContain('K02.1')
        ->and($html)->toContain('Dra. Pérez')
        ->and($html)->toContain('P-')
        ->and($html)->toContain('Alto')
        ->and($html)->toContain('Herramienta de apoyo; no constituye diagnóstico');
})->group('RF-180', 'RN-70', 'CUS-62');

it('lets the clinic request the copy and read it with the documents route', function () {
    Storage::fake('s3');
    $tenant = Tenant::factory()->create();
    $patient = ClinicalFixtures::patient($tenant);
    $receptionist = $this->actingAsRole('receptionist', $tenant);

    $id = $this->postJson("/api/v1/patients/{$patient->uuid}/clinical-record-copies", [], copyKey())
        ->assertCreated()
        ->json('data.id');
    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    $this->getJson("/api/v1/documents/{$id}")->assertOk()->assertJsonPath('data.status', 'listo');

    $audit = TenantContext::run($tenant, fn () => AuditLog::query()->where('action', 'export.requested')->sole());
    expect($audit->metadata)->toBe(['kind' => 'copia_hc'])
        ->and($audit->patient_uuid)->toBe($patient->uuid)
        ->and($audit->user_id)->toBe($receptionist->id);
})->group('RF-180', 'CUS-62', 'RN-67');

it('generates the copy while the clinic is suspended and keeps the other writes forbidden', function () {
    $tenant = Tenant::factory()->create(['status' => 'suspendida', 'status_reason' => 'Falta de pago']);
    $patient = ClinicalFixtures::patient($tenant);
    $this->actingAsRole('receptionist', $tenant);

    // Criterio 2 de TASK-102: la copia se genera y las demás escrituras responden 403 (RN-07, RN-70).
    $this->postJson("/api/v1/patients/{$patient->uuid}/clinical-record-copies", [], copyKey())->assertCreated();
    $this->patchJson("/api/v1/patients/{$patient->uuid}", ['phone' => '911222333'])->assertForbidden();
})->group('RN-70', 'RN-07', 'CUS-62');

it('hides from the portal the documents of other patients and other kinds', function () {
    Storage::fake('s3');
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    [, $other] = PortalFixtures::holder($tenant);
    $this->actingAsRole('receptionist', $tenant);
    $foreignCopy = $this->postJson("/api/v1/patients/{$other->uuid}/clinical-record-copies", [], copyKey())->json('data.id');
    PortalFixtures::issuedBudget($tenant, $patient);
    $budgetPdf = TenantContext::run($tenant, fn () => GeneratedDocument::query()->where('kind', 'presupuesto')->sole()->uuid);
    $this->actingWithToken($user);

    $this->getJson("/api/v1/portal/documents/{$foreignCopy}")->assertNotFound();
    $this->getJson("/api/v1/portal/documents/{$budgetPdf}")->assertNotFound();
    $this->postJson("/api/v1/portal/patients/{$other->uuid}/clinical-record-copies", [], copyKey())->assertNotFound();
})->group('RN-03', 'RF-180', 'CUS-62');
