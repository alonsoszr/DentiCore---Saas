<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\AttentionResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Models\RiskPrediction;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historia clínica (SDD §4.3; CUS-21; RF-064, RF-076): identificación, alergias, estado del
 * consentimiento, atenciones (sin notas clínicas), estado del odontograma inicial y, para el
 * administrador y el odontólogo, la predicción de riesgo vigente (SDD §3.5). Cada lectura queda
 * en la bitácora (RN-67).
 */
class ClinicalRecordController extends Controller
{
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $attentions = Attention::query()
            ->where('patient_id', $patient->id)
            ->with(['patient', 'dentist', 'signer'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get();
        $initial = InitialOdontogram::query()->where('patient_id', $patient->id)->first();
        // Predicción vigente (SDD §3.5; CUS-56): solo para el administrador y el odontólogo.
        $user = $request->user();
        $prediction = $user instanceof User && in_array($user->role, ['clinic_admin', 'dentist'], true)
            ? RiskPrediction::query()->where('patient_id', $patient->id)->where('status', 'vigente')->first()
            : null;

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
            'current_risk_prediction' => $prediction === null ? null : [
                'id' => $prediction->uuid,
                /** @var 'bajo'|'medio'|'alto' */
                'level' => $prediction->level,
                'confidence' => $prediction->confidence,
                'low_confidence' => (float) $prediction->confidence < 0.60,
                'predicted_at' => $prediction->predicted_at->toIso8601String(),
                'expires_at' => $prediction->expires_at->toIso8601String(),
            ],
        ]]);
    }
}
