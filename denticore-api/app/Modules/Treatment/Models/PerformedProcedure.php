<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Procedimiento realizado sobre un ítem aceptado (SDD §2.8 `performed_procedures`, §5.5; CUS-39;
 * RF-126, RN-38, RN-39, RN-76). Inmutable; solo la fusión de pacientes cambia `patient_id`
 * (DI-21). Guarda de antemano el uuid de su entrada de evolución en el odontograma.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $plan_item_id
 * @property int $attention_id
 * @property int $dentist_id
 * @property int $quantity
 * @property Carbon $performed_at
 * @property string|null $observations
 * @property int|null $informed_consent_id
 * @property string|null $odontogram_entry_uuid
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read PlanItem $planItem
 * @property-read Attention $attention
 * @property-read User $dentist
 * @property-read InformedConsent|null $informedConsent
 */
class PerformedProcedure extends Model
{
    use BelongsToTenant, HasUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'performed_at' => 'datetime',
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
     * @return BelongsTo<PlanItem, $this>
     */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * Consentimiento informado que se usó para el procedimiento (RN-76).
     *
     * @return BelongsTo<InformedConsent, $this>
     */
    public function informedConsent(): BelongsTo
    {
        return $this->belongsTo(InformedConsent::class);
    }

    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }
}
