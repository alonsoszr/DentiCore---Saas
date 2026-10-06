<?php

namespace App\Modules\Odontogram\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Diagnóstico CIE-10 de la atención (SDD §2.6 `attention_diagnoses`; RF-084, RF-085, RN-77,
 * RN-78): de la nota mientras la atención está abierta; después, solo desde una adenda.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $attention_id
 * @property string $cie10_code
 * @property string $type
 * @property string $origin
 * @property int|null $addendum_id
 * @property int $created_by
 * @property Carbon $created_at
 * @property-read Attention $attention
 * @property-read Cie10Code $cie10
 */
class AttentionDiagnosis extends Model
{
    use BelongsToTenant, HasUuid;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }

    /**
     * @return BelongsTo<Cie10Code, $this>
     */
    public function cie10(): BelongsTo
    {
        return $this->belongsTo(Cie10Code::class, 'cie10_code', 'code');
    }
}
