<?php

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Http\Requests\RiskVariableSetRequest;
use App\Modules\Risk\Http\Resources\RiskVariableSetResource;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\RiskVariableSetService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Variables de riesgo (SDD §4.3.9, §5.8.1; CUS-54; RF-161, RF-162, RF-164). La recepción registra
 * la sección sociodemográfica y el odontólogo la clínica y la conductual; el registro usado por una
 * predicción no cambia: editarlo crea otro que lo reemplaza (201). Otra clínica → 404.
 */
class RiskVariableSetController extends Controller
{
    public function __construct(private RiskVariableSetService $sets) {}

    /** Versiones del registro del paciente, de la más reciente a la más antigua (RF-164). */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        $sets = RiskVariableSet::query()
            ->where('patient_id', $patient->id)
            ->with(['clinicalCapturedBy', 'sociodemographicCapturedBy', 'supersedes'])
            ->latest('id')
            ->limit(20)
            ->get();

        return RiskVariableSetResource::collection($sets);
    }

    /** Registra secciones en el registro vigente del paciente (lo crea si no hay uno editable). */
    #[ProblemResponse(403, 'El rol no puede registrar esa sección (RF-162) o el plan no incluye riesgo (RN-08)')]
    #[ProblemResponse(422, 'Valor fuera de dominio (RF-161) o falta la finalidad (d) del consentimiento (RN-58)')]
    public function store(RiskVariableSetRequest $request, Patient $patient): JsonResponse
    {
        return $this->respond($this->sets->upsert($patient, $request->sections(), $this->user($request)));
    }

    /** Edita el registro indicado; si una predicción ya lo usó, crea otro que lo reemplaza (RF-164). */
    #[ProblemResponse(409, 'El registro fue reemplazado por uno más reciente (RF-164)')]
    #[ProblemResponse(422, 'Valor fuera de dominio (RF-161) o falta la finalidad (d) del consentimiento (RN-58)')]
    public function update(RiskVariableSetRequest $request, RiskVariableSet $set): JsonResponse
    {
        return $this->respond($this->sets->upsert($request->patient(), $request->sections(), $this->user($request), $set));
    }

    private function respond(RiskVariableSet $set): JsonResponse
    {
        $set->load(['clinicalCapturedBy', 'sociodemographicCapturedBy', 'supersedes']);

        // Un registro nuevo (el primero o el que reemplaza a uno bloqueado) responde 201.
        if ($set->wasRecentlyCreated) {
            return RiskVariableSetResource::make($set)->response()->setStatusCode(201);
        }

        return RiskVariableSetResource::make($set)->response();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
