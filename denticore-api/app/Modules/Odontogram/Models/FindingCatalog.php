<?php

namespace App\Modules\Odontogram\Models;

use Database\Factories\FindingCatalogFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hallazgo del catálogo de la NTS N° 188 (SDD §2.6 `finding_catalog`; RN-17, RF-078). Catálogo de
 * plataforma, cerrado y versionado; un hallazgo nunca se elimina, se retira (RF-078).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $acronym
 * @property string $level
 * @property string $dentition
 * @property string $introduced_in_version
 * @property string|null $retired_in_version
 * @property bool $is_active
 * @property int $display_order
 */
#[UseFactory(FindingCatalogFactory::class)]
class FindingCatalog extends Model
{
    /** @use HasFactory<FindingCatalogFactory> */
    use HasFactory;

    protected $table = 'finding_catalog';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * Estados del hallazgo; el estado elegido determina el color de la entrada (RN-17).
     *
     * @return HasMany<FindingState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(FindingState::class, 'finding_id');
    }
}
