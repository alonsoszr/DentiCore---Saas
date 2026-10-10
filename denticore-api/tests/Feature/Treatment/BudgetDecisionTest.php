<?php

/*
 * Decisión sobre el presupuesto (TASK-059; SDD §5.4.3; CUS-37; RF-011, RF-122, RF-123, RN-12,
 * RN-35, RN-36, RN-37, DD-46). La decisión desde el portal y por enlace llega en MS-06.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Audit\AuditLog;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
});

/**
 * Plan `propuesto` con dos ítems y dos presupuestos emitidos por la recepcionista autenticada:
 * el primero con los dos ítems y el segundo solo con el primero (vencen el 05/11/2026 a las 23:59).
 *
 * @param  array<string, mixed>  $patientAttributes
 * @return array{tenant: Tenant, receptionist: User, dentist: User, patient: Patient, plan: TreatmentPlan, items: list<PlanItem>, budgets: list<string>}
 */
function decisionScenario(array $patientAttributes = []): array
{
    $tenant = Tenant::factory()->create();
    $receptionist = test()->actingAsRole('receptionist', $tenant);

    $setup = TenantContext::run($tenant, function () use ($tenant, $patientAttributes): array {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'document_number' => '45678912', ...$patientAttributes]);
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'cop_number' => '12345']);
        $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto']);
        $items = array_map(fn (string $price) => PlanItem::factory()->create([
            'tenant_id' => $tenant->id,
            'treatment_plan_id' => $plan->id,
            'procedure_id' => Procedure::factory()->create(['tenant_id' => $tenant->id, 'price' => $price])->id,
        ]), ['150.00', '80.00']);

        return compact('patient', 'dentist', 'plan', 'items');
    });

    $budgets = [];
    foreach ([[], ['plan_item_ids' => [$setup['items'][0]->uuid]]] as $payload) {
        $budget = test()->postJson("/api/v1/treatment-plans/{$setup['plan']->uuid}/budgets", $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        test()->postJson("/api/v1/budgets/{$budget}/issue", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $budgets[] = $budget;
    }

    return ['tenant' => $tenant, 'receptionist' => $receptionist, ...$setup, 'budgets' => $budgets];
}

/** @param  array<string, mixed>  $payload */
function decideBudget(string $budgetUuid, array $payload = []): TestResponse
{
    return test()->post("/api/v1/budgets/{$budgetUuid}/decision", [
        'decision' => 'aceptado', 'signer' => 'titular', 'signer_document_number' => '45678912', ...$payload,
    ], ['Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]);
}

function decidedBudget(Tenant $tenant, string $uuid): Budget
{
    return TenantContext::run($tenant, fn () => Budget::query()->where('uuid', $uuid)->sole());
}

it('replaces the other issued budget of the plan on acceptance', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'items' => $items, 'budgets' => [$full, $partial]] = decisionScenario();

    decideBudget($partial)->assertOk()
        ->assertJsonPath('data.status', 'aceptado')
        ->assertJsonPath('data.decision.channel', 'presencial')
        ->assertJsonPath('data.decision.signer', 'titular');

    $replaced = decidedBudget($tenant, $full);
    expect($replaced->status)->toBe('reemplazado')
        ->and($replaced->replaced_at)->not->toBeNull();

    TenantContext::run($tenant, function () use ($plan, $items) {
        expect(TreatmentPlan::query()->whereKey($plan->id)->value('status'))->toBe('aceptado')
            ->and(PlanItem::query()->whereKey($items[0]->id)->value('status'))->toBe('aceptado')
            ->and(PlanItem::query()->whereKey($items[1]->id)->value('status'))->toBe('propuesto');
    });
})->group('T-089', 'CA-37.1', 'RN-37', 'RF-123', 'CUS-37');

it('rejects accepting a budget that expired yesterday at 23:59', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'budgets' => [$budget]] = decisionScenario();
    Carbon::setTestNow(Carbon::parse('2026-11-06 09:00', 'America/Lima'));

    decideBudget($budget)->assertConflict()
        ->assertJsonPath('rule', 'RN-35')
        ->assertJsonPath('detail', 'El presupuesto venció el 05/11/2026.');

    // El vencimiento se confirmó en su propia transacción aunque la respuesta sea 409.
    $expired = decidedBudget($tenant, $budget);
    expect($expired->status)->toBe('vencido')
        ->and($expired->expired_at)->not->toBeNull()
        ->and($expired->decided_at)->toBeNull()
        ->and(TenantContext::run($tenant, fn () => TreatmentPlan::query()->whereKey($plan->id)->value('status')))->toBe('propuesto');
})->group('T-090', 'CA-37.2', 'RN-35', 'CUS-37');

it('accepts only one of two concurrent acceptances', function () {
    ['tenant' => $tenant, 'budgets' => [$full, $partial]] = decisionScenario();

    decideBudget($full)->assertOk();

    // FE-3: la segunda decisión, serializada por el FOR UPDATE, encuentra el estado final.
    decideBudget($full)->assertConflict()->assertJsonPath('rule', 'RN-36')->assertJsonPath('detail', 'El presupuesto ya fue aceptado.');
    decideBudget($partial)->assertConflict()->assertJsonPath('detail', 'El presupuesto ya fue reemplazado.');

    // RN-37: la BD no admite un segundo presupuesto aceptado en el plan (budgets_accepted_unique).
    TenantContext::run($tenant, fn () => expect(fn () => DB::transaction(
        fn () => DB::table('budgets')->where('uuid', $partial)->update(['status' => 'aceptado', 'decided_at' => now()]),
    ))->toThrow(QueryException::class, 'budgets_accepted_unique'));
    expect(TenantContext::run($tenant, fn () => Budget::query()->where('status', 'aceptado')->count()))->toBe(1);
})->group('T-092', 'CA-37.4', 'RN-37', 'RF-011');

it('records the decision evidence and seals it', function () {
    ['tenant' => $tenant, 'receptionist' => $receptionist, 'patient' => $patient, 'budgets' => [$budget]] = decisionScenario();

    decideBudget($budget, ['signed_file' => UploadedFile::fake()->create('presupuesto-firmado.pdf', 120, 'application/pdf')])
        ->assertOk()
        ->assertJsonPath('data.decision.by.id', $receptionist->uuid)
        ->assertJsonPath('data.decision.ip', '127.0.0.1')
        ->assertJsonPath('data.decision.signed_file', true);

    $decided = decidedBudget($tenant, $budget);
    $sealer = app(EvidenceSealer::class);
    $payload = TenantContext::run($tenant, fn () => $decided->decisionEvidencePayload());

    expect($decided->decided_at)->not->toBeNull()
        ->and($decided->decision_by_user_id)->toBe($receptionist->id)
        ->and($decided->decision_user_agent)->not->toBeNull()
        ->and($decided->signed_file_id)->not->toBeNull()
        ->and($decided->decision_signer_document_hash)->toBe(TenantContext::run($tenant, fn () => Patient::query()->whereKey($patient->id)->value('document_hash')))
        ->and($sealer->verify($payload, (string) $decided->decision_evidence_hmac))->toBeTrue()
        ->and($sealer->verify([...$payload, 'total' => '1.00'], (string) $decided->decision_evidence_hmac))->toBeFalse();

    TenantContext::run($tenant, function () use ($budget) {
        expect(AuditLog::query()->where('action', 'budget.decided')->where('resource_uuid', $budget)->sole()->metadata)
            ->toEqual(['decision' => 'aceptado', 'channel' => 'presencial'])
            ->and(AuditLog::query()->where('action', 'plan.status_changed')->count())->toBe(1);
    });
})->group('RF-122', 'DD-46', 'CA-37.3', 'CUS-37');

it('notifies the dentist of the plan and the reception in the app on acceptance', function () {
    ['tenant' => $tenant, 'receptionist' => $receptionist, 'dentist' => $dentist, 'budgets' => [$budget]] = decisionScenario();

    decideBudget($budget)->assertOk();

    $notifications = TenantContext::run($tenant, fn () => Notification::query()
        ->where('event', 'presupuesto_aceptado')->where('channel', 'in_app')->get());
    expect($notifications->pluck('recipient_user_id')->all())->toEqualCanonicalizing([$dentist->id, $receptionist->id]);

    // El enlace abre el presupuesto en el área del personal de su clínica (/c/:slug/app/*).
    foreach ($notifications as $notification) {
        expect($notification->payload['links']['budget'])->toBe(config('app.spa_url')."/c/{$tenant->slug}/app/presupuestos/{$budget}");
    }
})->group('RF-122', 'CUS-37');

it('rejects a budget with an optional reason and keeps the plan proposed', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'budgets' => [$budget]] = decisionScenario();

    decideBudget($budget, ['decision' => 'rechazado', 'rejection_reason' => 'otra'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
    decideBudget($budget, ['decision' => 'rechazado', 'rejection_reason' => 'precio', 'rejection_detail' => 'Prefiere comparar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rechazado')
        ->assertJsonPath('data.decision.rejection_reason', 'precio')
        ->assertJsonPath('data.decision.rejection_detail', 'Prefiere comparar');

    expect(TenantContext::run($tenant, fn () => TreatmentPlan::query()->whereKey($plan->id)->value('status')))->toBe('propuesto')
        ->and(TenantContext::run($tenant, fn () => Notification::query()->where('event', 'presupuesto_aceptado')->count()))->toBe(0);
})->group('RF-122', 'CUS-37');

it('verifies the signer document against the record', function () {
    ['budgets' => [$budget]] = decisionScenario();

    decideBudget($budget, ['signer_document_number' => '11111111'])->assertUnprocessable()->assertJsonValidationErrors('signer_document_number');
    decideBudget($budget, ['signer' => 'representante'])->assertUnprocessable()->assertJsonValidationErrors('signer');
})->group('RF-122', 'CUS-37');

it('lets only the legal representative decide for a minor', function () {
    ['tenant' => $tenant, 'patient' => $minor, 'budgets' => [$budget]] = decisionScenario(['birth_date' => '2015-03-10']);
    $representative = TenantContext::run($tenant, fn () => app(LegalRepresentativeService::class)->add($minor, [
        'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
        'relationship' => 'madre', 'phone' => '912345678', 'valid_from' => '2026-01-01',
    ]));

    decideBudget($budget)->assertForbidden()->assertJsonPath('detail', 'La decisión corresponde al representante legal.');
    decideBudget($budget, ['signer' => 'representante', 'signer_document_number' => '41234567'])
        ->assertOk()
        ->assertJsonPath('data.decision.signer', 'representante');

    expect(decidedBudget($tenant, $budget)->decision_signer_document_hash)->toBe($representative->document_hash);
})->group('RN-12', 'CUS-37');

it('does not accept a budget of a cancelled plan', function () {
    ['tenant' => $tenant, 'plan' => $plan, 'budgets' => [$budget]] = decisionScenario();
    TenantContext::run($tenant, fn () => DB::table('treatment_plans')->where('id', $plan->id)
        ->update(['status' => 'cancelado', 'cancel_reason' => 'Plan duplicado', 'cancelled_at' => now()]));

    decideBudget($budget)->assertConflict();
    expect(decidedBudget($tenant, $budget)->status)->toBe('emitido');
})->group('RF-114', 'CUS-37');

it('lets only the reception and the clinic administrator register a decision', function () {
    ['tenant' => $tenant, 'budgets' => [$budget]] = decisionScenario();

    $this->actingAsRole('dentist', $tenant);
    decideBudget($budget)->assertForbidden();

    $this->actingAsRole('clinic_admin', $tenant);
    decideBudget($budget)->assertOk();
})->group('RN-06', 'CUS-37');
