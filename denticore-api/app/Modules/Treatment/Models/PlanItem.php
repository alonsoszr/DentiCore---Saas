<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Treatment\Policies\PlanItemPolicy;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ítem del plan de tratamiento (SDD §2.8 `plan_items`; RF-110, RN-26, RN-27, CA-39.4; estados de
 * SRS §5.5.2). La pieza y sus superficies se validan con ClinicalValidator y los CHECK de la BD.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $treatment_plan_id
 * @property int $position
 * @property int $procedure_id
 * @property int|null $tooth
 * @property list<string> $surfaces
 * @property int $quantity
 * @property int $performed_quantity
 * @property int|null $session_number
 * @property string|null $observations
 * @property string $status
 * @property string|null $discard_reason
 * @property string $origin
 * @property int|null $ai_suggestion_id
 * @property-read TreatmentPlan $plan
 * @property-read Procedure $procedure
 * @property-read Collection<int, OdontogramEntry> $findings
 */
#[UseFactory(PlanItemFactory::class)]
#[UsePolicy(PlanItemPolicy::class)]
class PlanItem extends Model
{
    /** @use HasFactory<PlanItemFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'tooth' => 'integer',
            'quantity' => 'integer',
            'performed_quantity' => 'integer',
            'session_number' => 'integer',
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
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    /**
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Hallazgos que atiende el ítem (RF-111, RN-27), por el uuid de la entrada del odontograma:
     * la tabla particionada no admite FK (SDD §2.1).
     *
     * @return BelongsToMany<OdontogramEntry, $this>
     */
    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(OdontogramEntry::class, 'plan_item_findings', 'plan_item_id', 'odontogram_entry_uuid', 'id', 'uuid');
    }
}
