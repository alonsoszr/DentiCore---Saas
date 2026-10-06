<?php

namespace App\Modules\Treatment\Models;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Procedimiento del catálogo de la clínica (SDD §2.8 `procedure_catalog`; RF-107, RF-109, RN-26,
 * RN-39, RN-76). Usado en planes o presupuestos, no se elimina: se desactiva (RF-109).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string $price
 * @property bool $requires_tooth
 * @property bool $requires_surface
 * @property int|null $resulting_finding_id
 * @property int|null $resulting_finding_state_id
 * @property bool $requires_informed_consent
 * @property bool $is_active
 * @property-read FindingCatalog|null $resultingFinding
 * @property-read FindingState|null $resultingFindingState
 */
#[UseFactory(ProcedureFactory::class)]
class Procedure extends Model
{
    /** @use HasFactory<ProcedureFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected $table = 'procedure_catalog';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'requires_tooth' => 'boolean',
            'requires_surface' => 'boolean',
            'requires_informed_consent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Hallazgo que deja el procedimiento en el odontograma (RN-39).
     *
     * @return BelongsTo<FindingCatalog, $this>
     */
    public function resultingFinding(): BelongsTo
    {
        return $this->belongsTo(FindingCatalog::class, 'resulting_finding_id');
    }

    /**
     * @return BelongsTo<FindingState, $this>
     */
    public function resultingFindingState(): BelongsTo
    {
        return $this->belongsTo(FindingState::class, 'resulting_finding_state_id');
    }
}
