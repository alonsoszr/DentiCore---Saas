<?php

namespace App\Modules\Odontogram\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read Attention $attention
 */
class AttentionDiagnosis extends Model
{
    use BelongsToTenant, HasUuid;

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }
}
