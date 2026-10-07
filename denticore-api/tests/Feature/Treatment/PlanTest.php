<?php

/*
 * Plan de tratamiento, decisión de no tratar, descarte y cancelación (TASK-056; SDD §4.3, §5.4;
 * CUS-33, CUS-34, CUS-40; RF-110 a RF-114, RF-129, RF-130, RN-26, RN-27; SRS §5.5.2).
 */

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicalFixtures;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/**
 * Odontólogo autenticado con una atención abierta, un hallazgo rojo (pieza 36, O y M) y un
 * procedimiento activo que exige pieza y superficie.
 *
 * @return array{tenant: Tenant, patient: string, attention: string, entry: string, procedure: string}
 */
function planScenario(): array
{
    ClinicalFixtures::cariesFinding();
    $tenant = Tenant::factory()->create();
    test()->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);
    $entry = ClinicalFixtures::recordFinding($attention)->assertCreated()->json('data.id');
    $procedure = Procedure::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Restauración con resina', 'requires_tooth' => true, 'requires_surface' => true,
    ]);

    return [
        'tenant' => $tenant,
        'patient' => TenantContext::run($tenant, fn () => $attention->patient()->value('uuid')),
        'attention' => $attention->uuid,
        'entry' => $entry,
        'procedure' => $procedure->uuid,
    ];
}

/** @param  array<string, mixed>  $payload */
function createPlan(string $patientUuid, array $payload): TestResponse
{
    return test()->postJson("/api/v1/patients/{$patientUuid}/treatment-plans", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

function pendingFindings(string $patientUuid): TestResponse
{
    return test()->getJson("/api/v1/patients/{$patientUuid}/pending-findings")->assertOk();
}

function planByUuid(Tenant $tenant, string $uuid): TreatmentPlan
{
    return TenantContext::run($tenant, fn () => TreatmentPlan::query()->where('uuid', $uuid)->sole());
}

/**
 * Plan del tenant en el estado indicado, con un ítem en el estado indicado.
 *
 * @return array{plan: TreatmentPlan, item: PlanItem}
 */
function planInStatus(Tenant $tenant, string $planStatus, string $itemStatus = 'propuesto'): array
{
    $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'status' => $planStatus]);
    $item = PlanItem::factory()->create([
        'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => $itemStatus,
        'performed_quantity' => $itemStatus === 'realizado' ? 1 : 0,
        'discard_reason' => $itemStatus === 'descartado' ? 'El paciente lo decidió' : null,
    ]);

    return ['plan' => $plan, 'item' => $item];
}

/**
 * Presupuesto aceptado del plan con una línea por ítem: [ítem, cantidad, subtotal de la línea].
 *
 * @param  list<array{PlanItem, int, string}>  $lines
 */
function acceptedBudgetFor(TreatmentPlan $plan, array $lines, string $total, bool $pricesIncludeIgv = true): Budget
{
    $budget = Budget::factory()->create([
        'tenant_id' => $plan->tenant_id, 'treatment_plan_id' => $plan->id, 'prices_include_igv' => $pricesIncludeIgv,
    ]);

    foreach ($lines as [$item, $quantity, $subtotal]) {
        BudgetLine::factory()->create([
            'tenant_id' => $plan->tenant_id, 'budget_id' => $budget->id, 'plan_item_id' => $item->id,
            'quantity' => $quantity, 'unit_price' => $subtotal, 'subtotal' => $subtotal,
        ]);
    }

    $base = Money::of($total)->dividedBy('1.18');
    TenantContext::run($plan->tenant_id, fn () => DB::table('budgets')->where('id', $budget->id)->update([
        'status' => 'aceptado', 'number' => 'P-'.str_pad((string) $budget->id, 6, '0', STR_PAD_LEFT),
        'issued_at' => now(), 'expires_at' => now()->addDays(30), 'decided_at' => now(),
        'subtotal' => $total, 'base_amount' => (string) $base, 'igv_amount' => (string) Money::of($total)->minus($base), 'total' => $total,
    ]));

    return $budget;
}

it('creates a draft plan with items linked to the red findings they treat', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'entry' => $entry, 'procedure' => $procedure] = planScenario();

    $response = createPlan($patient, [
        'title' => 'Plan de restauraciones',
        'items' => [[
            'procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O', 'M'], 'quantity' => 1,
            'session_number' => 1, 'observations' => 'Resina compuesta', 'finding_ids' => [$entry],
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Plan de restauraciones')
        ->assertJsonPath('data.status', 'borrador')
        ->assertJsonPath('data.patient_id', $patient)
        ->assertJsonPath('data.items.0.position', 1)
        ->assertJsonPath('data.items.0.procedure.id', $procedure)
        ->assertJsonPath('data.items.0.procedure.name', 'Restauración con resina')
        ->assertJsonPath('data.items.0.tooth', 36)
        ->assertJsonPath('data.items.0.surfaces', ['O', 'M'])
        ->assertJsonPath('data.items.0.status', 'propuesto')
        ->assertJsonPath('data.items.0.finding_ids', [$entry]);

    TenantContext::run($tenant, function () use ($response) {
        expect(DB::table('plan_item_findings')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'plan.created')->where('resource_uuid', $response->json('data.id'))->count())->toBe(1);
    });
})->group('RF-110', 'RF-111', 'RN-27', 'CUS-33');

it('requires tooth and surfaces for procedures that require them', function (array $item, string $field) {
    ['patient' => $patient, 'procedure' => $procedure] = planScenario();

    createPlan($patient, ['title' => 'Plan', 'items' => [['procedure_id' => $procedure, ...$item]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("items.0.{$field}");
})->with([
    'sin pieza' => [['surfaces' => ['O']], 'tooth'],
    'sin superficies' => [['tooth' => 36], 'surfaces'],
    'pieza inexistente (RN-16)' => [['tooth' => 19, 'surfaces' => ['O']], 'tooth'],
    'superficie que no aplica (RN-18)' => [['tooth' => 36, 'surfaces' => ['I']], 'surfaces'],
    'superficie repetida' => [['tooth' => 36, 'surfaces' => ['O', 'O']], 'surfaces'],
    'cantidad fuera de 1 a 32' => [['tooth' => 36, 'surfaces' => ['O'], 'quantity' => 33], 'quantity'],
])->group('T-095', 'RN-26', 'RF-110');

it('accepts an item without tooth when the procedure does not require it', function () {
    ['tenant' => $tenant, 'patient' => $patient] = planScenario();
    $prophylaxis = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_tooth' => false, 'requires_surface' => false]);

    createPlan($patient, ['title' => 'Prevención', 'items' => [['procedure_id' => $prophylaxis->uuid]]])
        ->assertCreated()
        ->assertJsonPath('data.items.0.tooth', null)
        ->assertJsonPath('data.items.0.surfaces', [])
        ->assertJsonPath('data.items.0.quantity', 1);
})->group('T-095', 'RN-26');

it('rejects inactive or foreign procedures', function () {
    ['tenant' => $tenant, 'patient' => $patient] = planScenario();
    $inactive = Procedure::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $foreign = Procedure::factory()->create();

    foreach ([$inactive->uuid, $foreign->uuid] as $procedure) {
        createPlan($patient, ['title' => 'Plan', 'items' => [['procedure_id' => $procedure, 'tooth' => 16]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.procedure_id');
    }
})->group('RN-26', 'RF-109');

it('links items only to current red findings of the same patient', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'attention' => $attentionUuid, 'procedure' => $procedure] = planScenario();
    $attention = ClinicalFixtures::attention($tenant, $attentionUuid);
    $blue = ClinicalFixtures::recordFinding($attention, ['tooth' => 46, 'state_code' => 'DETENIDA'])->json('data.id');
    $annulled = ClinicalFixtures::recordFinding($attention, ['tooth' => 47])->json('data.id');
    $this->postJson("/api/v1/odontogram-entries/{$annulled}/corrections", ['kind' => 'anulacion', 'reason' => 'Registrado por error'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $otherPatient = ClinicalFixtures::recordFinding(ClinicalFixtures::openAttention($tenant))->json('data.id');

    foreach ([$blue, $annulled, $otherPatient, (string) Str::uuid()] as $finding) {
        createPlan($patient, ['title' => 'Plan', 'items' => [[
            'procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O'], 'finding_ids' => [$finding],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.finding_ids');
    }
})->group('RN-27', 'RF-111');

it('lists red findings without item or decision as pending', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'attention' => $attentionUuid, 'entry' => $linked, 'procedure' => $procedure] = planScenario();
    $attention = ClinicalFixtures::attention($tenant, $attentionUuid);
    $decided = ClinicalFixtures::recordFinding($attention, ['tooth' => 46])->json('data.id');
    $pending = ClinicalFixtures::recordFinding($attention, ['tooth' => 26, 'surfaces' => ['O']])->json('data.id');
    ClinicalFixtures::recordFinding($attention, ['tooth' => 47, 'state_code' => 'DETENIDA']);

    expect(pendingFindings($patient)->json('data.*.id'))->toEqualCanonicalizing([$linked, $decided, $pending]);

    createPlan($patient, ['title' => 'Plan', 'items' => [[
        'procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O', 'M'], 'finding_ids' => [$linked],
    ]]])->assertCreated();
    $this->postJson("/api/v1/odontogram-entries/{$decided}/no-treat", ['reason' => 'El paciente prefiere observar la lesión'])
        ->assertCreated()
        ->assertJsonPath('data.finding_id', $decided)
        ->assertJsonPath('data.reason', 'El paciente prefiere observar la lesión');

    pendingFindings($patient)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending)
        ->assertJsonPath('data.0.tooth', 26)
        ->assertJsonPath('data.0.color', 'rojo');
    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'finding.no_treat')->count())->toBe(1));
})->group('T-096', 'RN-27', 'RF-112', 'RF-113', 'CUS-34');

it('returns a finding to pending when its item is discarded', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'entry' => $entry, 'procedure' => $procedure] = planScenario();
    $item = createPlan($patient, ['title' => 'Plan', 'items' => [[
        'procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O'], 'finding_ids' => [$entry],
    ]]])->json('data.items.0.id');
    pendingFindings($patient)->assertJsonCount(0, 'data');

    $this->postJson("/api/v1/plan-items/{$item}/discard", ['reason' => 'Se tratará en otra clínica'])
        ->assertOk()
        ->assertJsonPath('data.status', 'descartado')
        ->assertJsonPath('data.discard_reason', 'Se tratará en otra clínica');

    pendingFindings($patient)->assertJsonPath('data.0.id', $entry);
})->group('RN-27', 'RF-129', 'CUS-40');

it('rejects a no-treat decision with a short reason or on a finding that is not pending', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'attention' => $attentionUuid, 'entry' => $entry, 'procedure' => $procedure] = planScenario();
    $attention = ClinicalFixtures::attention($tenant, $attentionUuid);
    $blue = ClinicalFixtures::recordFinding($attention, ['tooth' => 46, 'state_code' => 'DETENIDA'])->json('data.id');
    $linked = ClinicalFixtures::recordFinding($attention, ['tooth' => 26, 'surfaces' => ['O']])->json('data.id');
    createPlan($patient, ['title' => 'Plan', 'items' => [[
        'procedure_id' => $procedure, 'tooth' => 26, 'surfaces' => ['O'], 'finding_ids' => [$linked],
    ]]])->assertCreated();
    $noTreat = fn (string $finding, string $reason) => $this->postJson("/api/v1/odontogram-entries/{$finding}/no-treat", ['reason' => $reason]);

    $noTreat($entry, '123456789')->assertUnprocessable()->assertJsonValidationErrors('reason');
    $noTreat($blue, 'Hallazgo azul sin tratamiento')->assertUnprocessable()->assertJsonPath('rule', 'RN-27');
    $noTreat($linked, 'Ya tiene un ítem del plan')->assertConflict()->assertJsonPath('rule', 'RN-27');
    $noTreat($entry, 'El paciente no desea tratarlo')->assertCreated();
    $noTreat($entry, 'Segunda decisión sobre lo mismo')->assertConflict()->assertJsonPath('rule', 'RN-27');
})->group('RF-113', 'RN-27', 'CUS-34');

it('edits the draft plan and its items', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'entry' => $entry, 'procedure' => $procedure] = planScenario();
    $plan = createPlan($patient, ['title' => 'Plan inicial'])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/treatment-plans/{$plan}", ['title' => 'Plan corregido'])->assertOk()->assertJsonPath('data.title', 'Plan corregido');
    $items = $this->postJson("/api/v1/treatment-plans/{$plan}/items", ['items' => [
        ['procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O'], 'finding_ids' => [$entry]],
        ['procedure_id' => $procedure, 'tooth' => 26, 'surfaces' => ['O']],
    ]])->assertCreated()->assertJsonCount(2, 'data.items');
    [$first, $second] = $items->json('data.items.*.id');

    $this->patchJson("/api/v1/plan-items/{$first}", ['surfaces' => ['O', 'M'], 'quantity' => 2, 'session_number' => 2])
        ->assertOk()
        ->assertJsonPath('data.surfaces', ['O', 'M'])
        ->assertJsonPath('data.quantity', 2)
        ->assertJsonPath('data.session_number', 2);
    $this->patchJson("/api/v1/plan-items/{$first}", ['surfaces' => ['I']])->assertUnprocessable()->assertJsonValidationErrors('surfaces');
    $this->deleteJson("/api/v1/plan-items/{$first}")->assertNoContent();

    $this->getJson("/api/v1/treatment-plans/{$plan}")->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $second);
    pendingFindings($patient)->assertJsonPath('data.0.id', $entry);
    TenantContext::run($tenant, fn () => expect(DB::table('plan_item_findings')->count())->toBe(0));
})->group('RF-110', 'CUS-33');

it('proposes a plan with proposed items and reopens it without an issued budget', function () {
    ['tenant' => $tenant, 'patient' => $patient, 'procedure' => $procedure] = planScenario();
    $plan = createPlan($patient, ['title' => 'Plan'])->json('data.id');

    $this->postJson("/api/v1/treatment-plans/{$plan}/propose")->assertUnprocessable()->assertJsonPath('rule', 'RF-114');
    $this->postJson("/api/v1/treatment-plans/{$plan}/items", ['items' => [['procedure_id' => $procedure, 'tooth' => 36, 'surfaces' => ['O']]]]);
    $this->postJson("/api/v1/treatment-plans/{$plan}/propose")->assertOk()->assertJsonPath('data.status', 'propuesto');
    $this->postJson("/api/v1/treatment-plans/{$plan}/reopen")->assertOk()->assertJsonPath('data.status', 'borrador');

    TenantContext::run($tenant, function () use ($plan) {
        $changes = AuditLog::query()->where('action', 'plan.status_changed')->where('resource_uuid', $plan)->orderBy('id')->pluck('metadata');
        expect($changes->all())->toEqual([
            ['from' => 'borrador', 'to' => 'propuesto'],
            ['from' => 'propuesto', 'to' => 'borrador'],
        ]);
    });
})->group('RF-114', 'CUS-33');

it('does not reopen a plan with a current issued budget', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['plan' => $plan] = planInStatus($tenant, 'propuesto');
    $budget = Budget::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id]);
    TenantContext::run($tenant, fn () => DB::table('budgets')->where('id', $budget->id)->update([
        'status' => 'emitido', 'number' => 'P-000001', 'issued_at' => now(), 'expires_at' => now()->addDays(30),
    ]));

    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/reopen")->assertConflict()->assertJsonPath('rule', 'RF-114');

    // Un presupuesto emitido cuya vigencia terminó ya no impide volver a editar.
    Carbon::setTestNow(now()->addDays(31));
    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/reopen")->assertOk()->assertJsonPath('data.status', 'borrador');
})->group('RF-114', 'CUS-33');

it('rejects undefined plan transitions with 409', function (string $status, string $action) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['plan' => $plan] = planInStatus($tenant, $status, in_array($status, ['completado'], true) ? 'realizado' : 'propuesto');

    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/{$action}", ['reason' => 'Motivo de la cancelación'])
        ->assertConflict()
        ->assertJsonPath('rule', 'RF-114');

    expect(planByUuid($tenant, $plan->uuid)->status)->toBe($status);
})->with([
    'completado → borrador' => ['completado', 'reopen'],
    'borrador → borrador' => ['borrador', 'reopen'],
    'aceptado → borrador' => ['aceptado', 'reopen'],
    'en ejecución → borrador' => ['en_ejecucion', 'reopen'],
    'cancelado → borrador' => ['cancelado', 'reopen'],
    'propuesto → propuesto' => ['propuesto', 'propose'],
    'aceptado → propuesto' => ['aceptado', 'propose'],
    'cancelado → propuesto' => ['cancelado', 'propose'],
    'completado → cancelado' => ['completado', 'cancel'],
    'cancelado → cancelado' => ['cancelado', 'cancel'],
])->group('T-097', 'RF-114');

it('only edits the plan and its items while it is a draft', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['plan' => $plan, 'item' => $item] = planInStatus($tenant, 'propuesto');
    $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_surface' => false]);

    $this->patchJson("/api/v1/treatment-plans/{$plan->uuid}", ['title' => 'Otro'])->assertConflict()->assertJsonPath('rule', 'RF-114');
    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/items", ['items' => [['procedure_id' => $procedure->uuid, 'tooth' => 16]]])->assertConflict();
    $this->patchJson("/api/v1/plan-items/{$item->uuid}", ['quantity' => 2])->assertConflict();
    $this->deleteJson("/api/v1/plan-items/{$item->uuid}")->assertConflict();
})->group('T-097', 'RF-114');

it('rejects undefined item transitions with 409', function (string $itemStatus) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['item' => $item] = planInStatus($tenant, 'en_ejecucion', $itemStatus);

    $this->postJson("/api/v1/plan-items/{$item->uuid}/discard", ['reason' => 'No corresponde'])
        ->assertConflict()
        ->assertJsonPath('rule', 'RF-114');
})->with(['realizado', 'descartado'])->group('T-097', 'RF-114');

it('lets the clinic administrator discard an accepted item and completes the plan with the last one', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    ['plan' => $plan, 'item' => $done] = planInStatus($tenant, 'en_ejecucion', 'realizado');
    $accepted = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'aceptado']);

    $this->postJson("/api/v1/plan-items/{$accepted->uuid}/discard", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/plan-items/{$accepted->uuid}/discard", ['reason' => 'El paciente desistió'])
        ->assertOk()
        ->assertJsonPath('data.status', 'descartado');

    $plan = planByUuid($tenant, $plan->uuid);
    expect($plan->status)->toBe('completado')
        ->and($plan->completed_at)->not->toBeNull();
})->group('CA-39.2', 'RF-129', 'CUS-40');

it('previews the cancellation with the performed items and value', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['plan' => $plan, 'item' => $done] = planInStatus($tenant, 'en_ejecucion', 'realizado');
    $partial = PlanItem::factory()->create([
        'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'aceptado', 'quantity' => 3, 'performed_quantity' => 1,
    ]);
    $pending = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'aceptado']);
    acceptedBudgetFor($plan, [[$done, 1, '150.00'], [$partial, 3, '100.00'], [$pending, 1, '80.00']], '330.00');

    $this->getJson("/api/v1/treatment-plans/{$plan->uuid}/cancellation-preview")->assertOk()
        ->assertJsonPath('data.items_total', 3)
        ->assertJsonPath('data.items_performed', 1)
        ->assertJsonPath('data.performed_amount', '183.33')
        ->assertJsonPath('data.accepted_amount', '330.00');

    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/treatment-plans/{$plan->uuid}/cancel", ['reason' => 'El paciente se mudó de ciudad'])->assertOk()
        ->assertJsonPath('data.status', 'cancelado')
        ->assertJsonPath('data.cancel_reason', 'El paciente se mudó de ciudad')
        ->assertJsonPath('data.progress.performed_amount', '183.33');

    expect(planByUuid($tenant, $plan->uuid)->cancelled_at)->not->toBeNull();
    $this->getJson("/api/v1/treatment-plans/{$plan->uuid}/cancellation-preview")->assertConflict()->assertJsonPath('rule', 'RF-114');
})->group('RF-129', 'CUS-40');

it('adds the IGV to the performed value when the budget prices exclude it', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    ['plan' => $plan, 'item' => $done] = planInStatus($tenant, 'en_ejecucion', 'realizado');
    acceptedBudgetFor($plan, [[$done, 1, '100.00']], '118.00', pricesIncludeIgv: false);

    $this->getJson("/api/v1/treatment-plans/{$plan->uuid}/cancellation-preview")->assertOk()
        ->assertJsonPath('data.performed_amount', '118.00')
        ->assertJsonPath('data.accepted_amount', '118.00');
})->group('RF-129', 'RN-30');

it('shows the progress of every plan of the patient', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    ['plan' => $plan, 'item' => $done] = planInStatus($tenant, 'en_ejecucion', 'realizado');
    $pending = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'aceptado']);
    PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'status' => 'descartado', 'discard_reason' => 'No aplica']);
    acceptedBudgetFor($plan, [[$done, 1, '150.00'], [$pending, 1, '50.00']], '200.00');
    TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $plan->patient_id, 'created_at' => now()->subDay()]);
    $patient = TenantContext::run($tenant, fn () => $plan->patient()->value('uuid'));

    $this->getJson("/api/v1/patients/{$patient}/treatment-plans")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $plan->uuid)
        ->assertJsonPath('data.0.progress.items_total', 2)
        ->assertJsonPath('data.0.progress.items_performed', 1)
        ->assertJsonPath('data.0.progress.performed_amount', '150.00')
        ->assertJsonPath('data.0.progress.accepted_amount', '200.00')
        ->assertJsonPath('data.1.progress.items_total', 0)
        ->assertJsonPath('data.1.progress.performed_amount', '0.00')
        ->assertJsonPath('data.1.progress.accepted_amount', null);
})->group('RF-130', 'CUS-33');

it('cancels a draft plan', function () {
    ['tenant' => $tenant, 'patient' => $patient] = planScenario();
    $plan = createPlan($patient, ['title' => 'Plan'])->json('data.id');

    $this->postJson("/api/v1/treatment-plans/{$plan}/cancel", ['reason' => 'Plan duplicado'])->assertOk()->assertJsonPath('data.status', 'cancelado');

    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'plan.status_changed')->sole()->metadata)
        ->toEqual(['from' => 'borrador', 'to' => 'cancelado']));
})->group('RF-129', 'CUS-40');
