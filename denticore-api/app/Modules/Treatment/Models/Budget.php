<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Presupuesto (SDD §2.8 `budgets`; CUS-35 a CUS-38; RN-28 a RN-37, DD-07, DD-23; estados de SRS
 * §5.5.3). Emitido, la BD solo admite cambios de estado, decisión, reemplazo y vencimiento
 * (`trg_budgets_immutable`, RN-34).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $treatment_plan_id
 * @property string|null $number
 * @property string $status
 * @property int|null $corrects_budget_id
 * @property bool $prices_include_igv
 * @property string $igv_rate
 * @property string $subtotal
 * @property string $discount_total
 * @property string $base_amount
 * @property string $igv_amount
 * @property string $total
 * @property int|null $validity_days
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property int|null $issued_by
 * @property int|null $dentist_id
 * @property string|null $terms_snapshot
 * @property int|null $pdf_document_id
 * @property string|null $decision_channel
 * @property int|null $decision_by_user_id
 * @property string|null $decision_signer
 * @property string|null $decision_signer_document_hash
 * @property Carbon|null $decided_at
 * @property string|null $decision_ip
 * @property string|null $decision_user_agent
 * @property string|null $rejection_reason
 * @property string|null $rejection_detail
 * @property int|null $signed_file_id
 * @property string|null $decision_evidence_hmac
 * @property Carbon|null $replaced_at
 * @property Carbon|null $expired_at
 * @property-read TreatmentPlan $plan
 * @property-read Patient $patient
 * @property-read Collection<int, BudgetLine> $lines
 */
#[UseFactory(BudgetFactory::class)]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'prices_include_igv' => 'boolean',
            'igv_rate' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'igv_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'validity_days' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'replaced_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
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
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
