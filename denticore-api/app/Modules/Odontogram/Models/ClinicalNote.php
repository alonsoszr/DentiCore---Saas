<?php

namespace App\Modules\Odontogram\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nota clínica de la atención (SDD §2.6 `clinical_notes`; RF-084, RN-77, RN-78). La BD rechaza
 * cambiarla firmada o con la atención fuera de `abierta`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $attention_id
 * @property string|null $chief_complaint
 * @property string|null $current_illness
 * @property string|null $extraoral_exam
 * @property string|null $intraoral_exam
 * @property string|null $indications
 * @property string $status
 * @property Carbon|null $autosaved_at
 * @property Carbon|null $signed_at
 * @property-read Attention $attention
 */
class ClinicalNote extends Model
{
    use BelongsToTenant, HasUuid;

    /** Secciones de la nota (RF-084), en el orden en que se presentan. */
    public const SECTIONS = ['chief_complaint', 'current_illness', 'extraoral_exam', 'intraoral_exam', 'indications'];

    protected function casts(): array
    {
        return [
            'autosaved_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }
}
