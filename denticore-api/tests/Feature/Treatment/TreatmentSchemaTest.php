<?php

/*
 * Esquema comercial y de consentimiento informado (TASK-054; SDD §2.8, §2.5, §2.6, §2.2; DD-06,
 * DD-07, DD-31, DI-21, RN-26, RN-27, RN-29 a RN-31, RN-34 a RN-37, RN-76).
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ejecuta `$statement` en el contexto de la clínica y devuelve el mensaje del error de la BD, o null. */
function dbErrorIn(int $tenantId, Closure $statement): ?string
{
    return TenantContext::run($tenantId, function () use ($statement) {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return $exception->getMessage();
        }

        return null;
    });
}

/** Emite el presupuesto directamente en la BD (el flujo real es BudgetIssuer, TASK-058). */
function issueBudgetRow(Budget $budget): void
{
    TenantContext::run($budget->tenant_id, fn () => DB::table('budgets')->where('id', $budget->id)->update([
        'status' => 'emitido', 'number' => 'P-'.str_pad((string) $budget->id, 6, '0', STR_PAD_LEFT),
        'validity_days' => 30, 'issued_at' => now(), 'expires_at' => now()->addDays(30),
        'subtotal' => 150, 'base_amount' => 127.12, 'igv_amount' => 22.88, 'total' => 150,
    ]));
}

/**
 * Fila del plan del ítem: paciente y odontólogo que lo creó.
 *
 * @return object{id: int, patient_id: int, created_by: int}
 */
function planOf(PlanItem $item): object
{
    return TenantContext::run($item->tenant_id, fn () => DB::table('treatment_plans')
        ->where('id', $item->treatment_plan_id)->select('id', 'patient_id', 'created_by')->first());
}

it('rejects any change to the lines of an issued budget', function () {
    $line = BudgetLine::factory()->create();
    $budget = TenantContext::run($line->tenant_id, fn () => Budget::query()->findOrFail($line->budget_id));
    $lines = fn () => DB::table('budget_lines')->where('id', $line->id);

    // En borrador las líneas se editan libremente.
    expect(dbErrorIn($line->tenant_id, fn () => $lines()->update(['quantity' => 2, 'subtotal' => 300])))->toBeNull();

    issueBudgetRow($budget);

    expect(dbErrorIn($line->tenant_id, fn () => $lines()->update(['quantity' => 3])))->toContain('immutable_row')
        ->and(dbErrorIn($line->tenant_id, fn () => $lines()->delete()))->toContain('immutable_row')
        ->and(dbErrorIn($line->tenant_id, fn () => BudgetLine::factory()->create([
            'tenant_id' => $line->tenant_id, 'budget_id' => $budget->id,
        ])))->toContain('immutable_row');

    TenantContext::run($line->tenant_id, fn () => expect($lines()->value('quantity'))->toBe(2));
})->group('RN-34', 'RF-120');

it('lets an issued budget record its decision, replacement and expiration only', function () {
    $budget = Budget::factory()->create();
    issueBudgetRow($budget);
    $budgets = fn () => DB::table('budgets')->where('id', $budget->id);
    $dentist = TenantContext::run($budget->tenant_id, fn () => DB::table('treatment_plans')
        ->where('id', $budget->treatment_plan_id)->value('created_by'));

    $decision = dbErrorIn($budget->tenant_id, fn () => $budgets()->update([
        'status' => 'aceptado', 'decision_channel' => 'presencial', 'decision_by_user_id' => $dentist,
        'decision_signer' => 'titular', 'decision_signer_document_hash' => hash('sha256', 'documento'),
        'decided_at' => now(), 'decision_ip' => '127.0.0.1', 'decision_user_agent' => 'Prueba',
        'decision_evidence_hmac' => hash('sha256', 'evidencia'),
    ]));
    $expiration = dbErrorIn($budget->tenant_id, fn () => $budgets()->update(['status' => 'vencido', 'expired_at' => now()]));
    $replacement = dbErrorIn($budget->tenant_id, fn () => $budgets()->update(['status' => 'reemplazado', 'replaced_at' => now()]));

    expect([$decision, $expiration, $replacement])->toBe([null, null, null]);

    foreach ([['total' => 999, 'base_amount' => 846.61, 'igv_amount' => 152.39], ['number' => 'P-999999'], ['prices_include_igv' => false], ['subtotal' => 1]] as $change) {
        expect(dbErrorIn($budget->tenant_id, fn () => $budgets()->update($change)))->toContain('immutable_row');
    }
})->group('RN-34', 'RF-120', 'RN-35', 'RN-36', 'RN-37');

it('requires a reason for any discount above zero', function (float $pct, ?string $reason, bool $valid) {
    $budget = Budget::factory()->create();

    $error = dbErrorIn($budget->tenant_id, fn () => BudgetLine::factory()->create([
        'tenant_id' => $budget->tenant_id, 'budget_id' => $budget->id, 'discount_pct' => $pct, 'discount_reason' => $reason,
    ]));

    $valid ? expect($error)->toBeNull() : expect($error)->toContain('budget_lines_discount_reason_check');
})->with([
    'sin descuento ni motivo' => [0, null, true],
    'descuento sin motivo' => [10, null, false],
    'motivo de 4 caracteres' => [10, 'Leal', false],
    'motivo de 5 caracteres' => [10, 'Socio', true],
])->group('RN-31');

it('enforces the CHECK constraints of the commercial schema', function (string $model, array $values, string $constraint) {
    $tenantId = Procedure::factory()->create()->tenant_id;

    $error = dbErrorIn($tenantId, fn () => match ($model) {
        'procedure' => Procedure::factory()->create(['tenant_id' => $tenantId, ...$values]),
        'item' => PlanItem::factory()->create(['tenant_id' => $tenantId, ...$values]),
        'budget' => Budget::factory()->create(['tenant_id' => $tenantId, ...$values]),
    });

    expect($error)->toContain($constraint);
})->with([
    'precio negativo' => ['procedure', ['price' => -1], 'procedure_catalog_price_check'],
    'precio sobre el máximo' => ['procedure', ['price' => 100000], 'procedure_catalog_price_check'],
    'superficie sin pieza' => ['procedure', ['requires_tooth' => false, 'requires_surface' => true], 'procedure_catalog_requires_check'],
    'pieza inexistente' => ['item', ['tooth' => 19], 'plan_items_tooth_check'],
    'superficies sin pieza' => ['item', ['tooth' => null, 'surfaces' => ['O']], 'plan_items_surfaces_check'],
    'oclusal en incisivo' => ['item', ['tooth' => 11, 'surfaces' => ['O']], 'plan_items_surfaces_check'],
    'cantidad 0' => ['item', ['quantity' => 0], 'plan_items_quantity_check'],
    'cantidad 33' => ['item', ['quantity' => 33], 'plan_items_quantity_check'],
    'realizado sobre lo planificado' => ['item', ['quantity' => 1, 'performed_quantity' => 2], 'plan_items_performed_quantity_check'],
    'descartado sin motivo' => ['item', ['status' => 'descartado', 'discard_reason' => null], 'plan_items_discard_reason_check'],
    'totales que no cuadran' => ['budget', ['base_amount' => 100, 'igv_amount' => 18, 'total' => 120], 'budgets_total_check'],
    'emitido sin número' => ['budget', ['status' => 'emitido'], 'budgets_issued_check'],
    'aceptado sin fecha de decisión' => ['budget', [
        'status' => 'aceptado', 'number' => 'P-000001', 'issued_at' => now(), 'expires_at' => now()->addDay(),
    ], 'budgets_decided_check'],
])->group('RN-26', 'RN-29', 'RN-30', 'RF-107', 'RF-110', 'RF-129');

it('keeps the unique keys of the catalog, the plan and the accepted budget', function () {
    $item = PlanItem::factory()->create();
    $tenantId = $item->tenant_id;
    $code = TenantContext::run($tenantId, fn () => DB::table('procedure_catalog')->where('id', $item->procedure_id)->value('code'));
    $accepted = Budget::factory()->create(['tenant_id' => $tenantId, 'treatment_plan_id' => $item->treatment_plan_id]);
    issueBudgetRow($accepted);
    TenantContext::run($tenantId, fn () => DB::table('budgets')->where('id', $accepted->id)->update(['status' => 'aceptado', 'decided_at' => now()]));
    $other = Budget::factory()->create(['tenant_id' => $tenantId, 'treatment_plan_id' => $item->treatment_plan_id]);
    issueBudgetRow($other);

    expect(dbErrorIn($tenantId, fn () => Procedure::factory()->create(['tenant_id' => $tenantId, 'code' => $code])))
        ->toContain('procedure_catalog_tenant_id_code_key')
        ->and(dbErrorIn($tenantId, fn () => PlanItem::factory()->create([
            'tenant_id' => $tenantId, 'treatment_plan_id' => $item->treatment_plan_id, 'position' => $item->position,
        ])))->toContain('plan_items_treatment_plan_id_position_key')
        ->and(dbErrorIn($tenantId, fn () => DB::table('budgets')->where('id', $other->id)->update(['status' => 'aceptado', 'decided_at' => now()])))
        ->toContain('budgets_accepted_unique');

    // El mismo código en otra clínica sí se admite.
    expect(Procedure::factory()->create(['code' => $code])->code)->toBe($code);
})->group('RN-37', 'RF-107', 'RF-110');

it('keeps no-treat decisions, performed procedures and consent template versions immutable', function () {
    $item = PlanItem::factory()->create();
    $tenantId = $item->tenant_id;
    $plan = planOf($item);
    $attention = Attention::factory()->create(['tenant_id' => $tenantId, 'patient_id' => $plan->patient_id]);

    $ids = TenantContext::run($tenantId, function () use ($tenantId, $plan, $item, $attention) {
        $templateId = DB::table('informed_consent_templates')->insertGetId([
            'tenant_id' => $tenantId, 'title' => 'Extracción simple', 'is_active' => true, 'current_version' => 1,
        ]);

        return [
            'informed_consent_template_versions' => DB::table('informed_consent_template_versions')->insertGetId([
                'tenant_id' => $tenantId, 'informed_consent_template_id' => $templateId, 'version' => 1,
                'body' => 'Yo, {{paciente}}, autorizo {{procedimiento}}.', 'body_sha256' => hash('sha256', 'v1'),
                'created_by' => $plan->created_by,
            ]),
            'finding_no_treat_decisions' => DB::table('finding_no_treat_decisions')->insertGetId([
                'tenant_id' => $tenantId, 'odontogram_entry_uuid' => (string) Str::uuid(), 'patient_id' => $plan->patient_id,
                'reason' => 'El paciente prefiere esperar', 'decided_by' => $plan->created_by,
            ]),
            'performed_procedures' => DB::table('performed_procedures')->insertGetId([
                'tenant_id' => $tenantId, 'patient_id' => $plan->patient_id, 'plan_item_id' => $item->id,
                'attention_id' => $attention->id, 'dentist_id' => $plan->created_by, 'quantity' => 1, 'performed_at' => now(),
            ]),
        ];
    });

    foreach ($ids as $table => $id) {
        expect(dbErrorIn($tenantId, fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->subDay()])))
            ->toContain('immutable_row')
            ->and(dbErrorIn($tenantId, fn () => DB::table($table)->where('id', $id)->delete()))
            ->toContain('immutable_row');
    }

    expect(dbErrorIn($tenantId, fn () => DB::table('finding_no_treat_decisions')->insert([
        'tenant_id' => $tenantId, 'odontogram_entry_uuid' => (string) Str::uuid(), 'patient_id' => $plan->patient_id,
        'reason' => 'Corto', 'decided_by' => $plan->created_by,
    ])))->toContain('finding_no_treat_decisions_reason_check');
})->group('RN-27', 'RN-38', 'RF-072', 'DI-21');

it('lets a patient merge reassign only patient_id and a retention delete remove the rows', function () {
    $item = PlanItem::factory()->create();
    $tenantId = $item->tenant_id;
    $plan = planOf($item);
    $target = Patient::factory()->create(['tenant_id' => $tenantId])->id;
    $budget = Budget::factory()->create(['tenant_id' => $tenantId, 'treatment_plan_id' => $plan->id]);
    issueBudgetRow($budget);
    $decisionId = TenantContext::run($tenantId, fn () => DB::table('finding_no_treat_decisions')->insertGetId([
        'tenant_id' => $tenantId, 'odontogram_entry_uuid' => (string) Str::uuid(), 'patient_id' => $plan->patient_id,
        'reason' => 'El paciente prefiere esperar', 'decided_by' => $plan->created_by,
    ]));
    $asMerge = fn (string $table, int $id, array $values) => dbErrorIn($tenantId, function () use ($table, $id, $values) {
        DB::select("select set_config('app.patient_merge', 'on', true)");
        DB::table($table)->where('id', $id)->update($values);
    });

    expect($asMerge('budgets', $budget->id, ['patient_id' => $target]))->toBeNull()
        ->and($asMerge('budgets', $budget->id, ['total' => 1]))->toContain('immutable_row')
        ->and($asMerge('finding_no_treat_decisions', $decisionId, ['patient_id' => $target]))->toBeNull()
        ->and($asMerge('finding_no_treat_decisions', $decisionId, ['reason' => 'Otro motivo cualquiera']))->toContain('immutable_row');

    expect(dbErrorIn($tenantId, function () use ($budget, $decisionId) {
        DB::select("select set_config('app.retention_delete', 'on', true)");
        DB::table('budgets')->where('id', $budget->id)->delete();
        DB::table('finding_no_treat_decisions')->where('id', $decisionId)->delete();
    }))->toBeNull();
})->group('DI-21', 'RN-34');

it('keeps a signed informed consent immutable except its status columns', function () {
    $item = PlanItem::factory()->create();
    $tenantId = $item->tenant_id;
    $plan = planOf($item);

    $row = TenantContext::run($tenantId, function () use ($tenantId, $plan, $item) {
        $templateId = DB::table('informed_consent_templates')->insertGetId([
            'tenant_id' => $tenantId, 'title' => 'Endodoncia', 'is_active' => true, 'current_version' => 1,
        ]);
        DB::table('procedure_informed_consent_template')->insert([
            'tenant_id' => $tenantId, 'procedure_id' => $item->procedure_id, 'informed_consent_template_id' => $templateId,
        ]);
        $versionId = DB::table('informed_consent_template_versions')->insertGetId([
            'tenant_id' => $tenantId, 'informed_consent_template_id' => $templateId, 'version' => 1,
            'body' => 'Yo, {{paciente}}, autorizo {{procedimiento}}.', 'body_sha256' => hash('sha256', 'v1'),
            'created_by' => $plan->created_by,
        ]);

        return [
            'tenant_id' => $tenantId, 'patient_id' => $plan->patient_id, 'plan_item_id' => $item->id,
            'template_version_id' => $versionId, 'rendered_text' => 'Texto firmado', 'text_sha256' => hash('sha256', 'texto'),
            'signer' => 'titular', 'channel' => 'dispositivo', 'informed_by' => $plan->created_by,
            'registered_by' => $plan->created_by, 'signed_at' => now(), 'evidence_hmac' => hash('sha256', 'evidencia'),
        ];
    });
    $consentId = TenantContext::run($tenantId, fn () => DB::table('informed_consents')->insertGetId($row));
    $consents = fn () => DB::table('informed_consents')->where('id', $consentId);

    expect(dbErrorIn($tenantId, fn () => $consents()->update(['rendered_text' => 'Otro texto'])))->toContain('immutable_row')
        ->and(dbErrorIn($tenantId, fn () => $consents()->update(['status' => 'revocado'])))->toContain('informed_consents_revocation_check')
        ->and(dbErrorIn($tenantId, fn () => $consents()->update([
            'status' => 'revocado', 'revoked_at' => now(), 'revocation_reason' => 'El paciente desiste',
        ])))->toBeNull()
        ->and(dbErrorIn($tenantId, fn () => $consents()->delete()))->toContain('immutable_row')
        ->and(dbErrorIn($tenantId, fn () => DB::table('informed_consents')->insert([...$row, 'channel' => 'papel'])))
        ->toContain('informed_consents_scanned_file_check')
        ->and(dbErrorIn($tenantId, fn () => DB::table('informed_consents')->insert([...$row, 'signer' => 'representante'])))
        ->toContain('informed_consents_representative_check');
})->group('RN-76', 'RF-073', 'RF-074', 'DD-31', 'RN-12');
