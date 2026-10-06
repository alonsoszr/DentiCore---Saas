<?php

namespace App\Modules\Odontogram\Models;

use Database\Factories\FindingStateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de un hallazgo del catálogo NTS 188 (SDD §2.6 `finding_states`; RN-17, RNF-151): define
 * el color de la entrada, azul o rojo, y su sigla cuando depende del estado.
 *
 * @property int $id
 * @property int $finding_id
 * @property string $code
 * @property string $name
 * @property string $color
 * @property string|null $acronym
 * @property bool $is_active
 * @property-read FindingCatalog $finding
 */
#[UseFactory(FindingStateFactory::class)]
class FindingState extends Model
{
    /** @use HasFactory<FindingStateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<FindingCatalog, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(FindingCatalog::class, 'finding_id');
    }
}
