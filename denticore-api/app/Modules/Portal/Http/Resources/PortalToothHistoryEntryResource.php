<?php

namespace App\Modules\Portal\Http\Resources;

use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Models\OdontogramEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Entrada del historial por pieza en el portal (CUS-24; RF-081, DI-13): la misma entrada que ve
 * el personal (hallazgo, estado, origen, autor con su COP y fecha) sin la nota clínica ni el
 * motivo de las correcciones (RF-179).
 *
 * @mixin OdontogramEntry
 */
class PortalToothHistoryEntryResource extends OdontogramEntryResource
{
    /** Claves que el portal nunca entrega. */
    private const HIDDEN = ['note', 'correction_reason'];

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return Arr::except(parent::fields($request), self::HIDDEN);
    }
}
