<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Services\ClinicalValidator;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Historial de una pieza (SDD §4.3; CUS-24; RF-081, CA-23.1): todas sus entradas en orden
 * cronológico, con las corregidas marcadas y su corrección enlazada. Lectura auditada (RN-67).
 */
class ToothHistoryController extends Controller
{
    public function __construct(private OdontogramStateService $odontograms, private ClinicalValidator $validator) {}

    public function show(Patient $patient, int $tooth): AnonymousResourceCollection
    {
        if (($error = $this->validator->toothError($tooth)) !== null) {
            throw ValidationException::withMessages(['tooth' => $error]);
        }

        return OdontogramEntryResource::collection($this->odontograms->toothHistory($patient, $tooth));
    }
}
