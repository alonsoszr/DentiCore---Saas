<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Services\ClinicSettingsService;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Http\BusinessRuleException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Borrador del presupuesto (SDD §5.4.2 pasos 1 y 2; CUS-35; RF-115, RF-119, RF-120, RN-28, RN-31,
 * RN-34). Mientras es `borrador` se editan sus descuentos y se recalcula con BudgetCalculator;
 * la emisión la hace BudgetIssuer.
 */
class BudgetService
{
    public const IMMUTABLE_MESSAGE = 'El presupuesto emitido no se puede modificar; use Corregir.';

    public function __construct(private ClinicSettingsService $settings) {}

    /**
     * Paso 1 y RN-28: una línea por ítem `propuesto` del plan `propuesto`, o por los indicados en
     * `$planItemUuids` (así se excluyen líneas, CUS-35 paso 3). El precio es el vigente del
     * catálogo; se vuelve a copiar al emitir (RN-33).
     *
     * @param  list<string>|null  $planItemUuids
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function createDraft(TreatmentPlan $plan, ?array $planItemUuids): Budget
    {
        return DB::transaction(function () use ($plan, $planItemUuids): Budget {
            $plan = TreatmentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->status !== 'propuesto') {
                throw new BusinessRuleException('RN-28', 'Solo se presupuesta un plan propuesto al paciente.', status: 409);
            }

            $items = $this->proposedItems($plan, $planItemUuids);
            $settings = $this->settings->current();

            $budget = new Budget;
            $budget->forceFill([
                'patient_id' => $plan->patient_id,
                'treatment_plan_id' => $plan->id,
                'status' => 'borrador',
                'prices_include_igv' => $settings->prices_include_igv,
                'igv_rate' => $this->platformIgvRate(),
            ])->save();

            foreach ($items as $item) {
                $line = new BudgetLine;
                $line->forceFill([
                    'budget_id' => $budget->id,
                    'plan_item_id' => $item->id,
                    'procedure_id' => $item->procedure_id,
                    'description' => Str::limit($item->procedure->name, 150, ''),
                    'tooth' => $item->tooth,
                    'surfaces' => $item->surfaces,
                    'unit_price' => $item->procedure->price,
                    'quantity' => $item->quantity,
                    'discount_pct' => '0.00',
                    'subtotal' => '0.00',
                ])->save();
            }

            $this->recalculate($budget);

            return $budget;
        });
    }

    /**
     * Paso 2 (RN-31): descuento de 0 a 100 % con motivo de 5 a 200 caracteres si es mayor que 0.
     * Sobre `clinic_settings.discount_cap_pct` solo el Administrador de Clínica, que queda como
     * `discount_approved_by`; los demás reciben 403 en esa línea y nada cambia.
     *
     * @param  array{discount_pct: int|float|string, discount_reason?: string|null}  $data
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function updateLine(Budget $budget, BudgetLine $line, array $data, User $actor): Budget
    {
        return DB::transaction(function () use ($budget, $line, $data, $actor): Budget {
            $budget = $this->lockedDraft($budget);
            $discount = BigDecimal::of((string) $data['discount_pct'])->toScale(2);
            $reason = $discount->isZero() ? null : trim((string) ($data['discount_reason'] ?? ''));

            if ($reason !== null && (mb_strlen($reason) < 5 || mb_strlen($reason) > 200)) {
                throw ValidationException::withMessages([
                    'discount_reason' => 'Indique el motivo del descuento (de 5 a 200 caracteres).',
                ]);
            }

            $cap = BigDecimal::of($this->settings->current()->discount_cap_pct);
            $aboveCap = $discount->isGreaterThan($cap);

            if ($aboveCap && $actor->role !== 'clinic_admin') {
                throw new BusinessRuleException(
                    'RN-31',
                    "El descuento supera el tope de la clínica ({$cap} %): solo el Administrador de Clínica puede aplicarlo.",
                    status: 403,
                );
            }

            $line->forceFill([
                'discount_pct' => (string) $discount,
                'discount_reason' => $reason,
                'discount_approved_by' => $aboveCap ? $actor->id : null,
            ])->save();

            $this->recalculate($budget);

            return $budget;
        });
    }

    /**
     * Descartar un borrador (SRS §5.5.3); sus líneas caen en cascada. Uno emitido no se borra.
     *
     * @throws BusinessRuleException
     */
    public function delete(Budget $budget): void
    {
        DB::transaction(function () use ($budget): void {
            $this->lockedDraft($budget)->delete();
        });
    }

    /**
     * RF-119 (SRS §11.9 FA-2): un borrador nuevo con las mismas líneas y descuentos que referencia
     * al emitido; el original no cambia.
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    public function correct(Budget $original): Budget
    {
        return DB::transaction(function () use ($original): Budget {
            $original = Budget::query()->whereKey($original->id)->lockForUpdate()->with('lines.planItem')->firstOrFail();

            if ($original->status !== 'emitido') {
                throw new BusinessRuleException('RF-119', 'Solo se corrige un presupuesto emitido.', status: 409);
            }

            $correction = $this->createDraft($original->plan, $original->lines->map(fn (BudgetLine $line) => $line->planItem->uuid)->all());
            $discounts = $original->lines->keyBy('plan_item_id');

            foreach ($correction->lines as $line) {
                $source = $discounts[$line->plan_item_id];
                $line->forceFill([
                    'discount_pct' => $source->discount_pct,
                    'discount_reason' => $source->discount_reason,
                    'discount_approved_by' => $source->discount_approved_by,
                ])->save();
            }

            $correction->forceFill(['corrects_budget_id' => $original->id]);
            $this->recalculate($correction);

            return $correction;
        });
    }

    /**
     * Subtotales por línea y totales del presupuesto con §5.4.1 (RN-29, RN-30); guarda ambos.
     */
    public function recalculate(Budget $budget): void
    {
        $lines = $budget->lines()->get();
        $result = BudgetCalculator::calculate(
            $lines->map(fn (BudgetLine $line) => [
                'unit_price' => $line->unit_price,
                'quantity' => $line->quantity,
                'discount_pct' => $line->discount_pct,
            ])->all(),
            $budget->prices_include_igv,
            $budget->igv_rate,
        );

        foreach ($lines->values() as $index => $line) {
            $line->forceFill(['subtotal' => (string) $result['line_subtotals'][$index]])->save();
        }

        $budget->forceFill([
            'subtotal' => (string) $result['subtotal'],
            'discount_total' => (string) $result['discount_total'],
            'base_amount' => (string) $result['base_amount'],
            'igv_amount' => (string) $result['igv_amount'],
            'total' => (string) $result['total'],
        ])->save();

        $budget->setRelation('lines', $lines);
    }

    /**
     * Tasa de IGV de la plataforma (`platform_settings.igv_rate`, RN-30), leída como texto para
     * no pasar por float (DI-05).
     *
     * @return numeric-string
     */
    public function platformIgvRate(): string
    {
        $value = trim((string) DB::table('platform_settings')->where('key', 'igv_rate')->value('value'), '" ');

        return (string) BigDecimal::of($value === '' ? '0.18' : $value)->toScale(4);
    }

    /**
     * @throws BusinessRuleException
     */
    public function lockedDraft(Budget $budget): Budget
    {
        $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

        if ($budget->status !== 'borrador') {
            throw new BusinessRuleException('RN-34', self::IMMUTABLE_MESSAGE, status: 409);
        }

        return $budget;
    }

    /**
     * @param  list<string>|null  $planItemUuids
     * @return list<PlanItem>
     *
     * @throws BusinessRuleException
     * @throws ValidationException
     */
    private function proposedItems(TreatmentPlan $plan, ?array $planItemUuids): array
    {
        $proposed = $plan->items()->where('status', 'propuesto')->with('procedure')->get()->keyBy('uuid');

        if ($planItemUuids === null) {
            if ($proposed->isEmpty()) {
                throw new BusinessRuleException('RN-28', 'El plan no tiene ítems propuestos para presupuestar.');
            }

            return $proposed->values()->all();
        }

        $errors = [];
        foreach ($planItemUuids as $index => $uuid) {
            if (! $proposed->has($uuid)) {
                $errors["plan_item_ids.{$index}"] = 'El ítem no está propuesto en este plan.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // En el orden del plan (posición), como el borrador completo. `toBase()`: el `only()` de
        // Eloquent filtra por clave primaria, no por la clave del arreglo (uuid).
        return $proposed->toBase()->only($planItemUuids)->values()->all();
    }
}
