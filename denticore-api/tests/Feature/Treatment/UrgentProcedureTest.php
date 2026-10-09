<?php

/*
 * Procedimiento de urgencia (TASK-061; SDD §5.5; CUS-39 FA-1; RF-012, RF-128, RN-38): plan,
 * presupuesto, aceptación presencial y procedimiento en una sola transacción, o nada.
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Outbox\OutboxMessage;
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
 * Atención abierta por el odontólogo autenticado y un procedimiento de restauración con hallazgo
 * resultante, sin plan previo.
 *
 * @param  array<string, mixed>  $procedure
 * @return array{tenant: Tenant, attention: Attention, procedure: Procedure, document: string}
 */
function urgentScenario(array $procedure = []): array
{
    $tenant = Tenant::factory()->create();
    test()->actingAsRole('dentist', $tenant, ['cop_number' => '12345']);
    $attention = ClinicalFixtures::openAttention($tenant);

    return TenantContext::run($tenant, function () use ($tenant, $attention, $procedure): array {
        $restoration = FindingCatalog::query()->where('code', 'RESTAURACION')->sole();

        return [
            'tenant' => $tenant,
            'attention' => $attention,
            'procedure' => Procedure::factory()->create([
                'tenant_id' => $tenant->id, 'name' => 'Restauración de urgencia', 'price' => '120.00',
                'requires_tooth' => true, 'requires_surface' => true,
                'resulting_finding_id' => $restoration->id,
                'resulting_finding_state_id' => $restoration->states()->where('code', 'R_BUENO')->value('id'),
                ...$procedure,
            ]),
            'document' => Patient::query()->whereKey($attention->patient_id)->sole()->document_number,
        ];
    });
}

/** @param  array<string, mixed>  $payload */
function performUrgent(Attention $attention, Procedure $procedure, string $document, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/attentions/{$attention->uuid}/urgent-procedures", [
        'procedure_id' => $procedure->uuid, 'tooth' => 36, 'surfaces' => ['O'], 'quantity' => 1,
        'observations' => 'Fractura de restauración', 'signer' => 'titular', 'signer_document_number' => $document,
        ...$payload,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

/**
 * @return array{plans: int, budgets: int, performed: int, sequence: int, documents: int}
 */
function urgentFootprint(Tenant $tenant): array
{
    return TenantContext::run($tenant, fn () => [
        'plans' => TreatmentPlan::query()->count(),
        'budgets' => Budget::query()->count(),
        'performed' => DB::table('performed_procedures')->count(),
        'sequence' => (int) DB::table('document_sequences')->where('doc_type', 'presupuesto')->value('last_value'),
        'documents' => OutboxMessage::query()->where('type', 'document.generate')->count(),
    ]);
}

it('creates plan, budget, acceptance and procedure in one attention or nothing', function () {
    ['tenant' => $tenant, 'attention' => $attention, 'procedure' => $procedure, 'document' => $document] = urgentScenario();

    $response = performUrgent($attention, $procedure, $document)->assertCreated()
        ->assertJsonPath('data.plan_item.status', 'realizado')
        ->assertJsonPath('data.plan.status', 'completado');

    TenantContext::run($tenant, function () use ($response) {
        $plan = TreatmentPlan::query()->where('uuid', $response->json('data.plan.id'))->sole();
        $budget = Budget::query()->where('uuid', $response->json('data.budget_id'))->sole();

        expect($plan->origin)->toBe('urgencia')
            ->and($budget->status)->toBe('aceptado')
            ->and($budget->number)->toBe('P-000001')
            ->and($budget->total)->toBe('120.00')
            ->and($budget->decision_channel)->toBe('presencial')
            ->and($budget->decision_signer)->toBe('titular')
            ->and($budget->decision_evidence_hmac)->not->toBeNull()
            ->and($response->json('data.odontogram_entry_id'))->not->toBeNull();
    });
    expect(urgentFootprint($tenant))->toMatchArray(['plans' => 1, 'budgets' => 1, 'performed' => 1, 'sequence' => 1]);
})->group('T-102', 'RF-128', 'RN-38', 'CUS-39');

it('leaves nothing when a step of the urgent procedure fails', function (string $case) {
    ['tenant' => $tenant, 'attention' => $attention, 'procedure' => $procedure, 'document' => $document] = urgentScenario(
        $case === 'consentimiento informado' ? ['requires_informed_consent' => true] : [],
    );
    $before = urgentFootprint($tenant);

    if ($case === 'pieza ausente') {
        ClinicalFixtures::recordFinding($attention, ['tooth' => 36, 'surfaces' => [], 'finding_code' => 'AUSENTE', 'state_code' => 'DEX', 'note' => null])->assertCreated();
    }

    $response = performUrgent($attention, $procedure, $case === 'documento distinto' ? '11111111' : $document)->assertUnprocessable();

    match ($case) {
        'documento distinto' => $response->assertJsonValidationErrors('signer_document_number'),
        'consentimiento informado' => $response->assertJsonPath('rule', 'RN-76'),
        'pieza ausente' => $response->assertJsonPath('detail', 'La pieza figura como ausente en el odontograma; corrija el odontograma si corresponde.'),
    };
    expect(urgentFootprint($tenant))->toBe($before);
})->with(['documento distinto', 'consentimiento informado', 'pieza ausente'])->group('T-102', 'RF-012', 'RF-128');

it('validates the urgent item like any plan item', function () {
    ['attention' => $attention, 'procedure' => $procedure, 'document' => $document] = urgentScenario();

    performUrgent($attention, $procedure, $document, ['tooth' => 19])->assertUnprocessable()->assertJsonValidationErrors('tooth');
    performUrgent($attention, $procedure, $document, ['procedure_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('procedure_id');
})->group('RN-26', 'CUS-39');
