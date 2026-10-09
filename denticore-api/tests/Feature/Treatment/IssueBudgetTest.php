<?php

/*
 * Borrador, emisión, corrección y PDF del presupuesto (TASK-058; SDD §5.4.2, §4.3; CUS-35, CUS-36;
 * RF-115, RF-116, RF-118 a RF-121, RN-28 a RN-35, DD-07, DD-18, DD-23).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Files\GeneratedDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Outbox;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/**
 * Plan `propuesto` con dos ítems: resina S/ 150,00 × 1 (pieza 16) y sellante S/ 80,00 × 2
 * (pieza 26), como en CA-35.1. La recepcionista está autenticada.
 *
 * @return array{tenant: Tenant, patient: Patient, plan: TreatmentPlan, items: list<PlanItem>, procedures: list<Procedure>}
 */
function budgetScenario(bool $notifications = false): array
{
    $tenant = Tenant::factory()->create();
    $receptionist = test()->actingAsRole('receptionist', $tenant);

    return TenantContext::run($tenant, function () use ($tenant, $receptionist, $notifications): array {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => 'paciente@ejemplo.test']);

        if ($notifications) {
            app(ConsentService::class)->grant($patient, [
                'channel' => 'presencial', 'purpose_notifications' => true, 'confirmation_document_number' => $patient->document_number,
            ], $receptionist, '127.0.0.1');
        }

        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'name' => 'Dra. Pérez', 'cop_number' => '12345']);
        $plan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto',
        ]);
        $resin = Procedure::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Restauración con resina', 'price' => '150.00']);
        $sealant = Procedure::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sellante de fosas', 'price' => '80.00']);

        return [
            'tenant' => $tenant,
            'patient' => $patient,
            'plan' => $plan,
            'procedures' => [$resin, $sealant],
            'items' => [
                PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $resin->id, 'tooth' => 16, 'quantity' => 1]),
                PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $sealant->id, 'tooth' => 26, 'quantity' => 2]),
            ],
        ];
    });
}

/** @param  array<string, mixed>  $payload */
function createBudget(TreatmentPlan $plan, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/treatment-plans/{$plan->uuid}/budgets", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

function issueBudget(string $budgetUuid): TestResponse
{
    return test()->postJson("/api/v1/budgets/{$budgetUuid}/issue", [], ['Idempotency-Key' => (string) Str::uuid()]);
}

/** @param  array<string, mixed>  $payload */
function discountLine(string $budgetUuid, string $lineUuid, array $payload): TestResponse
{
    return test()->patchJson("/api/v1/budgets/{$budgetUuid}/lines/{$lineUuid}", $payload);
}

function budgetByUuid(Tenant $tenant, string $uuid): Budget
{
    return TenantContext::run($tenant, fn () => Budget::query()->where('uuid', $uuid)->with('lines')->sole());
}

it('drafts one line per proposed item and issues the totals of CA-35.1', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'items' => $items, 'procedures' => $procedures] = budgetScenario();

    $draft = createBudget($plan)->assertCreated()
        ->assertJsonPath('data.status', 'borrador')
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.lines.0.plan_item_id', $items[0]->uuid)
        ->assertJsonPath('data.lines.0.procedure.id', $procedures[0]->uuid)
        ->assertJsonPath('data.lines.0.description', 'Restauración con resina')
        ->assertJsonPath('data.lines.0.tooth', 16)
        ->assertJsonPath('data.lines.0.unit_price', '150.00')
        ->assertJsonPath('data.lines.1.quantity', 2)
        ->assertJsonPath('data.total', '310.00');
    $budget = $draft->json('data.id');

    discountLine($budget, $draft->json('data.lines.1.id'), ['discount_pct' => 10, 'discount_reason' => 'Paciente frecuente'])
        ->assertOk()
        ->assertJsonPath('data.lines.1.discount_pct', '10.00')
        ->assertJsonPath('data.lines.1.subtotal', '144.00')
        ->assertJsonPath('data.total', '294.00');

    issueBudget($budget)->assertOk()
        ->assertJsonPath('data.status', 'emitido')
        ->assertJsonPath('data.number', 'P-000001')
        ->assertJsonPath('data.subtotal', '310.00')
        ->assertJsonPath('data.discount_total', '16.00')
        ->assertJsonPath('data.base_amount', '249.15')
        ->assertJsonPath('data.igv_amount', '44.85')
        ->assertJsonPath('data.total', '294.00')
        ->assertJsonPath('data.prices_include_igv', true)
        ->assertJsonPath('data.validity_days', 30)
        ->assertJsonPath('data.dentist.cop', '12345')
        ->assertJsonPath('data.pdf.status', 'pendiente');

    // RN-35: vence a las 23:59:59 de la clínica del día de emisión + vigencia.
    $issued = budgetByUuid($tenant, $budget);
    expect($issued->expires_at->setTimezone('America/Lima')->format('Y-m-d H:i:s'))->toBe('2026-11-05 23:59:59')
        ->and($issued->igv_rate)->toBe('0.1800');

    // Postcondición de CUS-35: el plan y sus ítems no cambian hasta la decisión.
    TenantContext::run($tenant, function () use ($plan, $budget) {
        expect(TreatmentPlan::query()->whereKey($plan->id)->value('status'))->toBe('propuesto')
            ->and(PlanItem::query()->where('treatment_plan_id', $plan->id)->pluck('status')->unique()->all())->toBe(['propuesto'])
            ->and(AuditLog::query()->where('action', 'budget.issued')->where('resource_uuid', $budget)->count())->toBe(1);
    });
    Outbox::assertRecorded('document.generate', times: 1);
})->group('CA-35.1', 'RF-115', 'RF-116', 'RN-29', 'RN-30', 'RN-35', 'CUS-35');

it('keeps the issued total after a catalog price change', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'procedures' => $procedures] = budgetScenario();
    $budget = createBudget($plan)->json('data.id');

    // El precio vigente se copia al emitir (RN-33), aunque cambie después de crear el borrador.
    TenantContext::run($tenant, fn () => $procedures[0]->forceFill(['price' => '160.00'])->save());
    issueBudget($budget)->assertOk()->assertJsonPath('data.lines.0.unit_price', '160.00')->assertJsonPath('data.total', '320.00');

    TenantContext::run($tenant, fn () => $procedures[0]->forceFill(['price' => '999.00'])->save());
    $this->getJson("/api/v1/budgets/{$budget}")->assertOk()
        ->assertJsonPath('data.lines.0.unit_price', '160.00')
        ->assertJsonPath('data.total', '320.00');
})->group('T-083', 'CA-35.2', 'RN-33');

it('does not issue a budget that contains an inactive procedure', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'procedures' => $procedures] = budgetScenario();
    $draft = createBudget($plan)->assertCreated();
    $budget = $draft->json('data.id');
    $inactiveLine = $draft->json('data.lines.0.id');
    TenantContext::run($tenant, fn () => $procedures[0]->forceFill(['is_active' => false])->save());

    issueBudget($budget)->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-32')
        ->assertJsonValidationErrors(["lines.{$inactiveLine}"])
        ->assertJsonMissingValidationErrors(['lines.'.$draft->json('data.lines.1.id')]);

    $after = budgetByUuid($tenant, $budget);
    expect($after->status)->toBe('borrador')
        ->and($after->number)->toBeNull()
        ->and(TenantContext::run($tenant, fn () => DB::table('document_sequences')->where('doc_type', 'presupuesto')->value('last_value')))->toBe(0);
})->group('T-084', 'CA-35.3', 'RN-32');

it('forbids a receptionist discount above the clinic cap', function () {
    ['tenant' => $tenant, 'plan' => $plan] = budgetScenario();
    $draft = createBudget($plan);
    [$budget, $line, $other] = [$draft->json('data.id'), $draft->json('data.lines.0.id'), $draft->json('data.lines.1.id')];
    discountLine($budget, $other, ['discount_pct' => 5, 'discount_reason' => 'Convenio']);

    discountLine($budget, $line, ['discount_pct' => 15, 'discount_reason' => 'Paciente frecuente'])
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-31');
    expect(budgetByUuid($tenant, $budget)->lines->pluck('discount_pct')->all())->toBe(['0.00', '5.00']);

    discountLine($budget, $line, ['discount_pct' => 10, 'discount_reason' => 'Paciente frecuente'])->assertOk();
    discountLine($budget, $line, ['discount_pct' => 5])->assertUnprocessable()->assertJsonValidationErrors('discount_reason');
    discountLine($budget, $line, ['discount_pct' => 5, 'discount_reason' => 'Uno'])->assertUnprocessable()->assertJsonValidationErrors('discount_reason');
    discountLine($budget, $line, ['discount_pct' => 100.5])->assertUnprocessable()->assertJsonValidationErrors('discount_pct');

    // FA-3: el Administrador de Clínica sí puede superar el tope, y queda registrado.
    $admin = $this->actingAsRole('clinic_admin', $tenant);
    discountLine($budget, $line, ['discount_pct' => 15, 'discount_reason' => 'Autorizado por la dirección'])
        ->assertOk()
        ->assertJsonPath('data.lines.0.discount_pct', '15.00');
    expect(TenantContext::run($tenant, fn () => DB::table('budget_lines')->where('uuid', $line)->value('discount_approved_by')))->toBe($admin->id);
})->group('T-085', 'CA-35.4', 'RN-31');

it('assigns consecutive distinct numbers to consecutive budgets', function () {
    ['plan' => $plan] = budgetScenario();

    $first = issueBudget(createBudget($plan)->json('data.id'))->assertOk()->json('data.number');
    $second = issueBudget(createBudget($plan)->json('data.id'))->assertOk()->json('data.number');
    expect([$first, $second])->toBe(['P-000001', 'P-000002']);

    // La numeración es por clínica (DD-23).
    ['plan' => $otherClinicPlan] = budgetScenario();
    issueBudget(createBudget($otherClinicPlan)->json('data.id'))->assertOk()->assertJsonPath('data.number', 'P-000001');
})->group('T-086', 'CA-35.5', 'DD-23');

it('rejects editing a line of an issued budget with 409 and at database level', function () {
    ['tenant' => $tenant, 'plan' => $plan] = budgetScenario();
    $draft = createBudget($plan);
    [$budget, $line] = [$draft->json('data.id'), $draft->json('data.lines.0.id')];
    issueBudget($budget)->assertOk();

    discountLine($budget, $line, ['discount_pct' => 5, 'discount_reason' => 'Convenio'])
        ->assertConflict()
        ->assertJsonPath('rule', 'RN-34')
        ->assertJsonPath('detail', 'El presupuesto emitido no se puede modificar; use Corregir.');
    issueBudget($budget)->assertConflict()->assertJsonPath('rule', 'RN-34');
    $this->deleteJson("/api/v1/budgets/{$budget}")->assertConflict()->assertJsonPath('rule', 'RN-34');

    TenantContext::run($tenant, fn () => expect(fn () => DB::transaction(
        fn () => DB::table('budget_lines')->where('uuid', $line)->update(['unit_price' => '1.00']),
    ))->toThrow(QueryException::class, 'immutable_row'));
})->group('T-087', 'CA-35.6', 'RN-34', 'RF-120');

it('rejects a budget without lines or with items that are not proposed', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'items' => $items] = budgetScenario();
    TenantContext::run($tenant, fn () => DB::table('plan_items')->where('id', $items[1]->id)
        ->update(['status' => 'descartado', 'discard_reason' => 'El paciente desistió']));

    createBudget($plan, ['plan_item_ids' => [$items[1]->uuid]])->assertUnprocessable()->assertJsonValidationErrors('plan_item_ids.0');
    createBudget($plan, ['plan_item_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('plan_item_ids');
    createBudget($plan)->assertCreated()->assertJsonCount(1, 'data.lines');

    TenantContext::run($tenant, fn () => DB::table('plan_items')->where('id', $items[0]->id)
        ->update(['status' => 'descartado', 'discard_reason' => 'El paciente desistió']));
    createBudget($plan)->assertUnprocessable()->assertJsonPath('rule', 'RN-28');

    // Precondición 1 de CUS-35: el plan está propuesto.
    TenantContext::run($tenant, fn () => DB::table('treatment_plans')->where('id', $plan->id)->update(['status' => 'borrador']));
    createBudget($plan)->assertConflict()->assertJsonPath('rule', 'RN-28');
})->group('T-088', 'RN-28');

it('drafts a budget with a subset of the proposed items', function () {
    ['plan' => $plan, 'items' => $items] = budgetScenario();

    createBudget($plan, ['plan_item_ids' => [$items[1]->uuid]])->assertCreated()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.plan_item_id', $items[1]->uuid)
        ->assertJsonPath('data.total', '160.00');
})->group('RN-28', 'RN-36', 'CUS-35');

it('corrects an issued budget with a new draft that references it', function () {
    ['tenant' => $tenant, 'plan' => $plan] = budgetScenario();
    $draft = createBudget($plan);
    $original = $draft->json('data.id');
    discountLine($original, $draft->json('data.lines.1.id'), ['discount_pct' => 10, 'discount_reason' => 'Paciente frecuente']);
    issueBudget($original)->assertOk();
    $before = TenantContext::run($tenant, fn () => (array) DB::table('budgets')->where('uuid', $original)->first());

    $correction = $this->postJson("/api/v1/budgets/{$original}/corrections", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'borrador')
        ->assertJsonPath('data.corrects_budget_id', $original)
        ->assertJsonPath('data.lines.1.discount_pct', '10.00')
        ->assertJsonPath('data.lines.1.discount_reason', 'Paciente frecuente')
        ->assertJsonPath('data.total', '294.00');

    expect(TenantContext::run($tenant, fn () => (array) DB::table('budgets')->where('uuid', $original)->first()))->toBe($before);
    $this->postJson("/api/v1/budgets/{$correction->json('data.id')}/corrections", [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertConflict();
})->group('RF-119', 'RN-34', 'CUS-35');

it('deletes only draft budgets', function () {
    ['plan' => $plan] = budgetScenario();
    $budget = createBudget($plan)->json('data.id');

    $this->deleteJson("/api/v1/budgets/{$budget}")->assertNoContent();
    $this->getJson("/api/v1/budgets/{$budget}")->assertNotFound();
})->group('RF-120', 'CUS-35');

it('generates the budget PDF in the documents queue and serves it with a signed URL', function () {
    ['tenant' => $tenant, 'plan' => $plan] = budgetScenario();
    $budget = createBudget($plan)->json('data.id');
    issueBudget($budget)->assertOk();

    $this->getJson("/api/v1/budgets/{$budget}/pdf")->assertOk()
        ->assertJsonPath('data.status', 'pendiente')
        ->assertJsonPath('data.url', null);
    $this->postJson("/api/v1/budgets/{$budget}/pdf/regenerate")->assertConflict();

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    $pdf = $this->getJson("/api/v1/budgets/{$budget}/pdf")->assertOk()->assertJsonPath('data.status', 'listo');
    expect($pdf->json('data.url'))->toContain('/files/')->toContain('signature=');

    $document = TenantContext::run($tenant, fn () => GeneratedDocument::query()->whereKey(budgetByUuid($tenant, $budget)->pdf_document_id)->sole());
    expect($document->kind)->toBe('presupuesto')
        ->and(Storage::disk('s3')->get(TenantContext::run($tenant, fn () => $document->storedFile->path)))->toStartWith('%PDF');

    $this->getJson("/api/v1/documents/{$document->uuid}")->assertOk()
        ->assertJsonPath('data.kind', 'presupuesto')
        ->assertJsonPath('data.status', 'listo');

    // FE-4: tras agotar los reintentos, el actor solicita la regeneración.
    TenantContext::run($tenant, fn () => $document->forceFill(['status' => 'fallido'])->save());
    $this->postJson("/api/v1/budgets/{$budget}/pdf/regenerate")->assertAccepted()->assertJsonPath('data.status', 'pendiente');
    expect(budgetByUuid($tenant, $budget)->pdf_document_id)->not->toBe($document->id);
})->group('RF-118', 'RF-121', 'DD-18', 'CUS-36');

it('notifies the patient only with the notifications purpose', function (bool $notifications) {
    ['tenant' => $tenant, 'plan' => $plan] = budgetScenario($notifications);

    issueBudget(createBudget($plan)->json('data.id'))->assertOk();

    expect(TenantContext::run($tenant, fn () => Notification::query()->where('event', 'presupuesto_emitido')->count()))
        ->toBe($notifications ? 1 : 0);
})->with(['con la finalidad (b)' => [true], 'sin la finalidad (b)' => [false]])->group('RF-155', 'CUS-35', 'CUS-52');

it('lists the budgets of the patient', function () {
    ['plan' => $plan, 'patient' => $patient] = budgetScenario();
    issueBudget(createBudget($plan)->json('data.id'))->assertOk();
    createBudget($plan)->assertCreated();

    $this->getJson("/api/v1/patients/{$patient->uuid}/budgets")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'borrador')
        ->assertJsonPath('data.1.number', 'P-000001')
        ->assertJsonPath('data.1.total', '310.00');
})->group('RF-121', 'CUS-36');
