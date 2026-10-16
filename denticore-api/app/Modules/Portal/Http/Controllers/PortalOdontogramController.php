<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Odontogram\Services\ClinicalValidator;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Http\Resources\PortalToothHistoryEntryResource;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Historial por pieza en el portal (SDD §4.3.10; CUS-24; RF-081): las entradas de una pieza del
 * paciente o de su representado, sin notas clínicas (DI-13).
 */
class PortalOdontogramController extends Controller
{
    public function __construct(private OdontogramStateService $odontograms, private ClinicalValidator $validator) {}

    #[ProblemResponse(422, 'La pieza no existe en el Sistema Dígito Dos (RN-16)')]
    public function toothHistory(Patient $patient, int $tooth): AnonymousResourceCollection
    {
        if (($error = $this->validator->toothError($tooth)) !== null) {
            throw ValidationException::withMessages(['tooth' => $error]);
        }

        return PortalToothHistoryEntryResource::collection($this->odontograms->toothHistory($patient, $tooth));
    }
}
