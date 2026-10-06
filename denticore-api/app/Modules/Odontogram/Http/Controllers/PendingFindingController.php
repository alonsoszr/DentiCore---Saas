<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Services\NoTreatDecisionService;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Hallazgos pendientes de decisión (SDD §4.3; CUS-34; RF-112, RN-27): rojos vigentes sin ítem de
 * plan ni decisión de no tratar.
 */
class PendingFindingController extends Controller
{
    public function __construct(private NoTreatDecisionService $decisions) {}

    public function index(Patient $patient): AnonymousResourceCollection
    {
        return OdontogramEntryResource::collection($this->decisions->pending($patient));
    }
}
