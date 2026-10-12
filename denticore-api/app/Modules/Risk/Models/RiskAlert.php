<?php

namespace App\Modules\Risk\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RiskAlertFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerta de riesgo alto (SDD §2.11 `risk_alerts`; CUS-57, CUS-58; RN-61, RN-65, RF-171). La FK
 * compuesta con `prediction_level = 'alto'` impide alertar una predicción de otro nivel. Solo su
 * odontólogo la reconoce, con una acción: plan preventivo, cita de control o justificación.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $risk_prediction_id
 * @property string $prediction_level
 * @property int $patient_id
 * @property int $dentist_id
 * @property string $status
 * @property string|null $action
 * @property int|null $treatment_plan_id
 * @property int|null $appointment_id
 * @property string|null $justification
 * @property Carbon|null $acknowledged_at
 * @property Carbon $created_at
 * @property-read RiskPrediction $prediction
 * @property-read Patient $patient
 * @property-read User $dentist
 * @property-read TreatmentPlan|null $treatmentPlan
 * @property-read Appointment|null $appointment
 */
#[UseFactory(RiskAlertFactory::class)]
class RiskAlert extends Model
{
    /** @use HasFactory<RiskAlertFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<RiskPrediction, $this>
     */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(RiskPrediction::class, 'risk_prediction_id');
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
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
