<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TreatmentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property-read Patient $patient
 * @property-read User $creator
 * @property-read Collection<int, PlanItem> $items
 * @property-read Collection<int, Budget> $budgets
 */
#[UseFactory(TreatmentPlanFactory::class)]
class TreatmentPlan extends Model
{
    /** @use HasFactory<TreatmentPlanFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

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
}
