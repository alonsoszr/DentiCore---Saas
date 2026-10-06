<?php

namespace App\Modules\Odontogram\Models;

use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\InitialOdontogramFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Odontograma inicial del paciente (SDD §2.6 `initial_odontograms`; RN-20): uno por paciente,
 * abierto durante su primera atención; una vez `cerrado` la BD rechaza cualquier cambio.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $attention_id
 * @property string $status
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property-read Patient $patient
 * @property-read Attention $attention
 */
#[UseFactory(InitialOdontogramFactory::class)]
class InitialOdontogram extends Model
{
    /** @use HasFactory<InitialOdontogramFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }
}
