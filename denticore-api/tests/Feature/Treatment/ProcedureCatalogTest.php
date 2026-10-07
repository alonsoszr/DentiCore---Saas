<?php

/*
 * Catálogo de procedimientos de la clínica (TASK-055; SDD §2.8, §4.3; CUS-32; RF-107, RF-109,
 * RN-26, RN-33, RN-39, RN-76).
 */

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Rules\ActiveProcedure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function procedurePayload(array $overrides = []): array
{
    return [
        'code' => 'RES-01', 'name' => 'Restauración con resina', 'category' => 'Operatoria', 'price' => '150.00',
        'requires_tooth' => true, 'requires_surface' => true, 'requires_informed_consent' => false, ...$overrides,
    ];
}

it('lets the clinic administrator create a procedure with its resulting finding', function () {
    $finding = FindingCatalog::query()->where('code', 'RESTAURACION')->sole();
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $response = $this->postJson('/api/v1/procedures', procedurePayload([
        'resulting_finding_code' => 'RESTAURACION', 'resulting_state_code' => 'R_BUENO',
    ]))->assertCreated()
        ->assertJsonPath('data.code', 'RES-01')
        ->assertJsonPath('data.price', '150.00')
        ->assertJsonPath('data.requires_surface', true)
        ->assertJsonPath('data.resulting_finding.code', 'RESTAURACION')
        ->assertJsonPath('data.resulting_state.acronym', 'R')
        ->assertJsonPath('data.resulting_state.color', 'azul')
        ->assertJsonPath('data.is_active', true);

    TenantContext::run($tenant, fn () => expect(Procedure::query()->where('uuid', $response->json('data.id'))->sole())
        ->resulting_finding_id->toBe($finding->id)
        ->tenant_id->toBe($tenant->id));
})->group('RF-107', 'RN-39', 'CUS-32');

it('rejects a duplicated code and invalid prices and flags', function (array $payload, string $field) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    Procedure::factory()->create(['tenant_id' => $tenant->id, 'code' => 'RES-01']);

    $this->postJson('/api/v1/procedures', procedurePayload($payload))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'código repetido en la clínica' => [[], 'code'],
    'precio negativo' => [['code' => 'X-1', 'price' => '-1'], 'price'],
    'precio sobre el máximo' => [['code' => 'X-1', 'price' => '100000.00'], 'price'],
    'más de dos decimales' => [['code' => 'X-1', 'price' => '10.505'], 'price'],
    'superficie sin pieza' => [['code' => 'X-1', 'requires_tooth' => false, 'requires_surface' => true], 'requires_surface'],
    'hallazgo inexistente' => [['code' => 'X-1', 'resulting_finding_code' => 'NO_EXISTE', 'resulting_state_code' => 'BUENO'], 'resulting_finding_code'],
    'estado de otro hallazgo' => [['code' => 'X-1', 'resulting_finding_code' => 'RESTAURACION', 'resulting_state_code' => 'CD'], 'resulting_state_code'],
    'hallazgo sin estado' => [['code' => 'X-1', 'resulting_finding_code' => 'RESTAURACION'], 'resulting_state_code'],
])->group('RF-107', 'RN-26', 'RN-39');

it('accepts the same code in another clinic', function () {
    Procedure::factory()->create(['code' => 'RES-01']);
    $this->actingAsRole('clinic_admin');

    $this->postJson('/api/v1/procedures', procedurePayload())->assertCreated();
})->group('RF-107', 'RN-01');

it('lists the catalog of the clinic to the staff', function (string $role) {
    $tenant = Tenant::factory()->create();
    Procedure::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Profilaxis', 'category' => 'Prevención']);
    Procedure::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Endodoncia', 'is_active' => false]);
    Procedure::factory()->create(['name' => 'De otra clínica']);
    $this->actingAsRole($role, $tenant);

    $this->getJson('/api/v1/procedures')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.name', ['Profilaxis', 'Endodoncia']);
})->with(['clinic_admin', 'dentist', 'receptionist'])->group('RF-107', 'CUS-32');

it('updates and deactivates a procedure without touching what was already issued', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    $line = BudgetLine::factory()->create(['tenant_id' => $tenant->id]);
    $procedure = TenantContext::run($tenant, fn () => Procedure::query()->findOrFail($line->procedure_id));

    $this->patchJson("/api/v1/procedures/{$procedure->uuid}", ['price' => '180.50', 'name' => 'Resina compuesta'])->assertOk()
        ->assertJsonPath('data.price', '180.50')
        ->assertJsonPath('data.name', 'Resina compuesta');
    $this->patchJson("/api/v1/procedures/{$procedure->uuid}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/v1/procedures/{$procedure->uuid}", ['requires_tooth' => false, 'requires_surface' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('requires_surface');

    // RN-33: la línea del presupuesto conserva su precio.
    TenantContext::run($tenant, fn () => expect(DB::table('budget_lines')->where('id', $line->id)->value('unit_price'))->toBe('150.00'));
})->group('RF-107', 'RN-33', 'CUS-32');

it('deletes an unused procedure and only deactivates one used in plans or budgets', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    $unused = Procedure::factory()->create(['tenant_id' => $tenant->id]);
    $inPlan = TenantContext::run($tenant, fn () => Procedure::query()->findOrFail(PlanItem::factory()->create(['tenant_id' => $tenant->id])->procedure_id));
    $inBudget = TenantContext::run($tenant, fn () => Procedure::query()->findOrFail(BudgetLine::factory()->create(['tenant_id' => $tenant->id])->procedure_id));

    $this->deleteJson("/api/v1/procedures/{$unused->uuid}")->assertNoContent();
    foreach ([$inPlan, $inBudget] as $used) {
        $this->deleteJson("/api/v1/procedures/{$used->uuid}")->assertConflict()
            ->assertJsonPath('rule', 'RF-109')
            ->assertJsonPath('detail', 'El procedimiento se usó en planes o presupuestos: no se puede eliminar, solo desactivar.');
        $this->patchJson("/api/v1/procedures/{$used->uuid}", ['is_active' => false])->assertOk();
    }

    TenantContext::run($tenant, fn () => expect(Procedure::query()->pluck('id')->all())->toEqualCanonicalizing([$inPlan->id, $inBudget->id]));
})->group('RF-109', 'RN-33', 'CUS-32');

it('rejects an inactive procedure for a new plan item', function () {
    $tenant = Tenant::factory()->create();
    $active = Procedure::factory()->create(['tenant_id' => $tenant->id]);
    $inactive = Procedure::factory()->create(['tenant_id' => $tenant->id, 'is_active' => false]);
    $foreign = Procedure::factory()->create();

    TenantContext::run($tenant, function () use ($active, $inactive, $foreign) {
        $validate = fn (string $uuid) => Validator::make(['procedure_id' => $uuid], ['procedure_id' => [new ActiveProcedure]]);

        expect($validate($active->uuid)->passes())->toBeTrue()
            ->and($validate($inactive->uuid)->errors()->first('procedure_id'))->toBe('El procedimiento está inactivo: no puede agregarse a un plan.')
            ->and($validate($foreign->uuid)->errors()->first('procedure_id'))->toBe('El procedimiento no existe en el catálogo de la clínica.')
            ->and($validate((string) Str::uuid())->fails())->toBeTrue();
    });
})->group('RN-26', 'RF-109');

it('lets only the clinic administrator change the catalog', function (string $role) {
    $tenant = Tenant::factory()->create();
    $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id]);
    $this->actingAsRole($role, $tenant);

    $this->postJson('/api/v1/procedures', procedurePayload())->assertForbidden();
    $this->patchJson("/api/v1/procedures/{$procedure->uuid}", ['price' => '1.00'])->assertForbidden();
    $this->deleteJson("/api/v1/procedures/{$procedure->uuid}")->assertForbidden();
})->with(['dentist', 'receptionist'])->group('CUS-32', 'RN-06');

it('keeps the catalog of other clinics out of reach', function () {
    $foreign = Procedure::factory()->create();
    $this->actingAsRole('clinic_admin');

    $this->patchJson("/api/v1/procedures/{$foreign->uuid}", ['price' => '1.00'])->assertNotFound();
    $this->deleteJson("/api/v1/procedures/{$foreign->uuid}")->assertNotFound();
})->group('RN-03', 'RF-003');

it('needs an active finding state for the resulting finding', function () {
    $finding = FindingCatalog::factory()->has(FindingState::factory()->state(['code' => 'VIEJO', 'is_active' => false]), 'states')->create(['code' => 'PRUEBA_EST_INACT']);
    $this->actingAsRole('clinic_admin');

    $this->postJson('/api/v1/procedures', procedurePayload([
        'resulting_finding_code' => $finding->code, 'resulting_state_code' => 'VIEJO',
    ]))->assertUnprocessable()->assertJsonValidationErrors('resulting_state_code');
})->group('RN-39', 'RF-078');
