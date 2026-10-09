<?php

/*
 * Procedimiento realizado (TASK-061; SDD §5.5; CUS-39; RF-126, RF-130, RN-38, RN-39; SRS §11.11).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
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
 * Atención abierta por el odontólogo autenticado y plan `aceptado` de su paciente con un ítem
 * `aceptado` de restauración (hallazgo resultante RESTAURACION / R_BUENO) en la pieza 36.
 *
 * @param  array<string, mixed>  $procedure
 * @param  array<string, mixed>  $item
 * @return array{tenant: Tenant, dentist: User, attention: Attention, plan: TreatmentPlan, procedure: Procedure, item: PlanItem}
 */
function performedScenario(array $procedure = [], array $item = []): array
{
    $tenant = Tenant::factory()->create();
    $dentist = test()->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);

    return TenantContext::run($tenant, function () use ($tenant, $dentist, $attention, $procedure, $item): array {
        $restoration = FindingCatalog::query()->where('code', 'RESTAURACION')->sole();
        $procedureModel = Procedure::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Restauración con resina',
            'requires_tooth' => true,
            'requires_surface' => true,
            'resulting_finding_id' => $restoration->id,
            'resulting_finding_state_id' => $restoration->states()->where('code', 'R_BUENO')->value('id'),
            ...$procedure,
        ]);
        $plan = TreatmentPlan::factory()->create([
            'tenant_id' => $tenant->id, 'patient_id' => $attention->patient_id, 'created_by' => $dentist->id, 'status' => 'aceptado',
        ]);
        $itemModel = PlanItem::factory()->create([
            'tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedureModel->id,
            'tooth' => 36, 'surfaces' => ['O'], 'quantity' => 1, 'status' => 'aceptado', ...$item,
        ]);

        return ['tenant' => $tenant, 'dentist' => $dentist, 'attention' => $attention, 'plan' => $plan, 'procedure' => $procedureModel, 'item' => $itemModel];
    });
}

/** @param  array<string, mixed>  $payload */
function performItem(PlanItem $item, Attention $attention, array $payload = []): TestResponse
{
    return test()->postJson(
        "/api/v1/plan-items/{$item->uuid}/performed-procedures",
        ['attention_id' => $attention->uuid, 'quantity' => 1, ...$payload],
        ['Idempotency-Key' => (string) Str::uuid()],
    );
}

function planStatus(Tenant $tenant, TreatmentPlan $plan): string
{
    return TenantContext::run($tenant, fn () => TreatmentPlan::query()->whereKey($plan->id)->value('status'));
}

it('creates an evolution entry with procedure origin on tooth 36', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'plan' => $plan, 'item' => $item] = performedScenario();

    $response = performItem($item, $attention, ['observations' => 'Resina compuesta A2'])->assertCreated()
        ->assertJsonPath('data.quantity', 1)
        ->assertJsonPath('data.observations', 'Resina compuesta A2')
        ->assertJsonPath('data.dentist.cop', '12345')
        ->assertJsonPath('data.attention_id', $attention->uuid)
        ->assertJsonPath('data.plan_item.status', 'realizado')
        ->assertJsonPath('data.plan_item.performed_quantity', 1)
        ->assertJsonPath('data.plan.status', 'completado');
    $entryId = $response->json('data.odontogram_entry_id');

    TenantContext::run($tenant, function () use ($entryId, $response) {
        $entry = OdontogramEntry::query()->where('uuid', $entryId)->with(['finding', 'findingState'])->sole();
        expect($entry->entry_type)->toBe('evolucion')
            ->and($entry->origin)->toBe('procedimiento')
            ->and($entry->tooth)->toBe(36)
            ->and($entry->surfaces)->toBe(['O'])
            ->and($entry->finding->code)->toBe('RESTAURACION')
            ->and($entry->findingState->code)->toBe('R_BUENO')
            ->and($entry->performed_procedure_id)->toBe(DB::table('performed_procedures')->where('uuid', $response->json('data.id'))->value('id'))
            ->and(AuditLog::query()->where('action', 'procedure.performed')->count())->toBe(1);
    });

    // El plan pasó por en_ejecucion antes de completarse (SRS §5.5.2).
    TenantContext::run($tenant, fn () => expect(AuditLog::query()->where('action', 'plan.status_changed')->orderBy('id')->pluck('metadata')->all())
        ->toEqual([['from' => 'aceptado', 'to' => 'en_ejecucion'], ['from' => 'en_ejecucion', 'to' => 'completado']]));
})->group('T-098', 'CA-39.1', 'RN-39', 'RF-126', 'CUS-39');

it('completes the plan when the last pending item is performed', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'plan' => $plan, 'item' => $first, 'procedure' => $procedure] = performedScenario();
    [$second, $discarded] = TenantContext::run($tenant, fn () => [
        PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id, 'tooth' => 46, 'surfaces' => ['O'], 'status' => 'aceptado']),
        PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id, 'status' => 'descartado', 'discard_reason' => 'El paciente desistió']),
    ]);

    performItem($first, $attention)->assertCreated()->assertJsonPath('data.plan.status', 'en_ejecucion');
    expect(planStatus($tenant, $plan))->toBe('en_ejecucion');

    performItem($second, $attention)->assertCreated()->assertJsonPath('data.plan.status', 'completado');
    expect(TenantContext::run($tenant, fn () => TreatmentPlan::query()->whereKey($plan->id)->value('completed_at')))->not->toBeNull()
        ->and(TenantContext::run($tenant, fn () => PlanItem::query()->whereKey($discarded->id)->value('status')))->toBe('descartado');
})->group('T-099', 'CA-39.2', 'RN-38', 'RF-130');

it('rejects performing a proposed item with 409', function () {
    ['attention' => $attention, 'item' => $item] = performedScenario(item: ['status' => 'propuesto']);

    performItem($item, $attention)->assertConflict()
        ->assertJsonPath('rule', 'RN-38')
        ->assertJsonPath('detail', 'El ítem no pertenece a un presupuesto aceptado.');
})->group('T-100', 'CA-39.3', 'RN-38');

it('rejects a quantity greater than the pending one', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'item' => $item] = performedScenario(item: ['quantity' => 3]);

    performItem($item, $attention, ['quantity' => 2])->assertCreated()
        ->assertJsonPath('data.plan_item.status', 'aceptado')
        ->assertJsonPath('data.plan_item.performed_quantity', 2);
    performItem($item, $attention, ['quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    performItem($item, $attention, ['quantity' => 1])->assertCreated()->assertJsonPath('data.plan_item.status', 'realizado');

    expect(TenantContext::run($tenant, fn () => DB::table('performed_procedures')->where('plan_item_id', $item->id)->count()))->toBe(2);
})->group('T-101', 'CA-39.4', 'RN-38');

it('does not create an odontogram entry for a procedure without resulting finding', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'item' => $item] = performedScenario(
        ['name' => 'Profilaxis', 'requires_tooth' => false, 'requires_surface' => false, 'resulting_finding_id' => null, 'resulting_finding_state_id' => null],
        ['tooth' => null, 'surfaces' => []],
    );

    performItem($item, $attention)->assertCreated()->assertJsonPath('data.odontogram_entry_id', null);
    expect(TenantContext::run($tenant, fn () => OdontogramEntry::query()->where('origin', 'procedimiento')->count()))->toBe(0);
})->group('RN-39', 'CUS-39');

it('rejects a procedure on a tooth recorded as absent', function (array $absence) {
    ['tenant' => $tenant, 'attention' => $attention, 'item' => $item] = performedScenario();
    ClinicalFixtures::recordFinding($attention, ['note' => null, ...$absence])->assertCreated();

    performItem($item, $attention)->assertUnprocessable()
        ->assertJsonPath('detail', 'La pieza figura como ausente en el odontograma; corrija el odontograma si corresponde.');
    expect(TenantContext::run($tenant, fn () => DB::table('performed_procedures')->count()))->toBe(0);
})->with([
    'pieza ausente' => [['tooth' => 36, 'surfaces' => [], 'finding_code' => 'AUSENTE', 'state_code' => 'DEX']],
    'edéntulo total inferior' => [['tooth' => 38, 'tooth_end' => 48, 'surfaces' => [], 'finding_code' => 'EDENTULO_TOTAL', 'state_code' => 'PRESENTE']],
])->group('CUS-39', 'RN-38');

it('performs on a tooth outside an edentulous span of the other arch', function () {
    ['attention' => $attention, 'item' => $item] = performedScenario();
    ClinicalFixtures::recordFinding($attention, [
        'tooth' => 18, 'tooth_end' => 28, 'surfaces' => [], 'finding_code' => 'EDENTULO_TOTAL', 'state_code' => 'PRESENTE', 'note' => null,
    ])->assertCreated();

    performItem($item, $attention)->assertCreated();
})->group('CUS-39');

it('requires an open attention of the patient in charge of the dentist', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'item' => $item] = performedScenario();

    performItem($item, $attention, ['observations' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('observations');

    // Atención de otro paciente del mismo odontólogo.
    $other = ClinicalFixtures::openAttention($tenant);
    performItem($item, $other)->assertUnprocessable()->assertJsonValidationErrors('attention_id');

    // Atención a cargo de otro odontólogo.
    $this->actingAsRole('dentist', $tenant, ['cop_number' => '67890']);
    performItem($item, $attention)->assertForbidden();

    // FE-4: atención cerrada.
    TenantContext::run($tenant, fn () => DB::table('attentions')->where('id', $attention->id)->update(['status' => 'cerrada', 'closed_at' => now()]));
    $this->actingWithToken(User::query()->findOrFail($attention->dentist_id));
    performItem($item, $attention)->assertConflict();

    $this->actingAsRole('receptionist', $tenant);
    performItem($item, $attention)->assertForbidden();
})->group('CUS-39', 'RN-19');
