<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Policies\TreatmentPlanPolicy;
use App\Support\Database\HasUuid;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TreatmentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Plan de tratamiento (SDD §2.8 `treatment_plans`; CUS-33, CUS-40; RF-110, RF-129; estados de
 * SRS §5.5.2). `risk_alert_id` se agrega en TASK-078.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property string $title
 * @property string $status
 * @property string $origin
 * @property int $created_by
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read User $creator
 * @property-read Collection<int, PlanItem> $items
 * @property-read Collection<int, Budget> $budgets
 * @property-read Budget|null $acceptedBudget
 */
#[UseFactory(TreatmentPlanFactory::class)]
#[UsePolicy(TreatmentPlanPolicy::class)]
class TreatmentPlan extends Model
{
    /** @use HasFactory<TreatmentPlanFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    /**
     * Transiciones del plan definidas en SRS §5.5.2; cualquier otra responde 409 (RF-114).
     */
    public const TRANSITIONS = [
        'borrador' => ['propuesto', 'cancelado'],
        'propuesto' => ['borrador', 'aceptado', 'cancelado'],
        'aceptado' => ['en_ejecucion', 'cancelado'],
        'en_ejecucion' => ['completado', 'cancelado'],
        'completado' => [],
        'cancelado' => [],
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlanItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Budget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * El presupuesto aceptado del plan; a lo más uno (RN-37, `budgets_accepted_unique`).
     *
     * @return HasOne<Budget, $this>
     */
    public function acceptedBudget(): HasOne
    {
        return $this->hasOne(Budget::class)->where('status', 'aceptado');
    }

    /**
     * Avance del plan (RF-130) y resumen de la vista previa de cancelación (RF-129): ítems
     * realizados sobre los no descartados y valor de lo realizado sobre el monto aceptado. El
     * valor de cada línea del presupuesto aceptado se prorratea por la cantidad realizada; si
     * los precios no incluyen IGV, se le suma como en SDD §5.4.1 para compararlo con el total.
     *
     * @return array{items_total: int, items_performed: int, performed_amount: string, accepted_amount: string|null}
     */
    public function progress(): array
    {
        $budget = $this->acceptedBudget;
        $performed = Money::zero();

        if ($budget !== null) {
            $performedQuantities = $this->items->pluck('performed_quantity', 'id');

            foreach ($budget->lines as $line) {
                $quantity = (int) ($performedQuantities[$line->plan_item_id] ?? 0);

                if ($quantity > 0) {
                    $performed = $performed->plus(Money::of($line->subtotal)->multipliedBy($quantity)->dividedBy($line->quantity));
                }
            }

            if (! $budget->prices_include_igv) {
                $performed = $performed->plus($performed->multipliedBy($budget->igv_rate));
            }
        }

        return [
            'items_total' => $this->items->where('status', '!=', 'descartado')->count(),
            'items_performed' => $this->items->where('status', 'realizado')->count(),
            'performed_amount' => (string) $performed,
            'accepted_amount' => $budget?->total,
        ];
    }

    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }
}
