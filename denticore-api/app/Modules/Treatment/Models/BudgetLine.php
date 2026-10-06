<?php

namespace App\Modules\Treatment\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\BudgetLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea del presupuesto (SDD §2.8 `budget_lines`; RF-118, RN-29, RN-31 a RN-34): copia del ítem
 * del plan con el precio vigente al emitir. Solo cambia mientras el presupuesto es borrador.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $budget_id
 * @property int $plan_item_id
 * @property int $procedure_id
 * @property string $description
 * @property int|null $tooth
 * @property list<string> $surfaces
 * @property string $unit_price
 * @property int $quantity
 * @property string $discount_pct
 * @property string|null $discount_reason
 * @property int|null $discount_approved_by
 * @property string $subtotal
 * @property-read Budget $budget
 * @property-read PlanItem $planItem
 * @property-read Procedure $procedure
 */
#[UseFactory(BudgetLineFactory::class)]
class BudgetLine extends Model
{
    /** @use HasFactory<BudgetLineFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'tooth' => 'integer',
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'discount_pct' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * `surfaces` es text[] de PostgreSQL ("{M,O}"); las letras de RN-18 no requieren comillas.
     *
     * @return Attribute<list<string>, list<string>|string>
     */
    protected function surfaces(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null || $value === '{}' ? [] : explode(',', trim($value, '{}')),
            set: fn (array|string $value): string => is_string($value) ? $value : '{'.implode(',', $value).'}',
        );
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<PlanItem, $this>
     */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
