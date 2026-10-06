<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Http\Resources\AttentionResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Historia clínica (SDD §4.3; CUS-21; RF-064, RF-076): identificación, alergias, estado del
 * consentimiento, atenciones (sin notas clínicas) y estado del odontograma inicial. La
 * predicción vigente se agrega desde MS-05. Cada lectura queda en la bitácora (RN-67).
 */
class ClinicalRecordController extends Controller
{
    public function show(Patient $patient): JsonResponse
    {
        $attentions = Attention::query()
            ->where('patient_id', $patient->id)
            ->with(['patient', 'dentist', 'signer'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get();
        $initial = InitialOdontogram::query()->where('patient_id', $patient->id)->first();

        return response()->json(['data' => [
            'patient' => PatientResource::make($patient),
            'attentions' => AttentionResource::collection($attentions),
            'initial_odontogram' => $initial === null ? null : [
                /** @var 'abierto'|'cerrado' */
                'status' => $initial->status,
                /** @var string|null */
                'closed_at' => $initial->closed_at,
                /** @var 'cierre_atencion'|'cierre_automatico'|null */
                'closed_by' => $initial->closed_by,
            ],
        ]]);
    }
}
