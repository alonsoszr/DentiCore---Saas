<?php

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Http\Resources\RiskPredictionResource;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\RiskPredictionService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Predicción de riesgo de caries (SDD §4.3.9; CUS-55, CUS-56, CUS-57; RF-165 a RF-170). Con el
 * motor caído o sin versión activa responde 200 «Predicción no disponible» y la atención sigue
 * (RN-64, CA-55.3). No hay endpoint de explicación aparte: viaja dentro de la predicción (RN-62).
 */
class RiskPredictionController extends Controller
{
    public function __construct(private RiskPredictionService $predictions) {}

    /** Calcula el riesgo con el registro de variables indicado (201), o repite el de hace menos de 60 s (200). */
    #[ProblemResponse(403, 'El plan de la clínica no incluye la predicción de riesgo (RN-08)')]
    #[ProblemResponse(422, 'Falta la finalidad (d), el registro no es el más reciente o faltan variables (`missing`, RN-58, CA-55.4)')]
    public function store(Request $request, Patient $patient): JsonResponse
    {
        /** @var array{risk_variable_set_id: string} $data */
        $data = $request->validate(['risk_variable_set_id' => ['required', 'uuid']]);
        $set = RiskVariableSet::query()->where('uuid', $data['risk_variable_set_id'])->where('patient_id', $patient->id)->first()
            ?? throw ValidationException::withMessages(['risk_variable_set_id' => 'El registro de variables no existe para este paciente.']);
        $dentist = $request->user();
        assert($dentist instanceof User);

        $result = $this->predictions->predict($patient, $set, $dentist);

        if ($result->prediction === null) {
            return response()->json(['data' => [
                /** @var 'no_disponible' */
                'status' => 'no_disponible',
                'message' => 'Predicción no disponible',
            ]]);
        }

        $result->prediction->load(['modelVersion', 'alert']);

        if ($result->created) {
            return RiskPredictionResource::make($result->prediction)->response()->setStatusCode(201);
        }

        return RiskPredictionResource::make($result->prediction)->response();
    }

    /** Predicción con su explicación según el rol (§3.5). Otra clínica → 404. */
    public function show(RiskPrediction $prediction): RiskPredictionResource
    {
        return RiskPredictionResource::make($prediction->load(['modelVersion', 'alert']));
    }
}
