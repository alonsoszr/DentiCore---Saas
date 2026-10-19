<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Services\ClinicalRecordCopyService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Copia de la historia clínica pedida en la clínica (SDD §4.3.11; CUS-62, RF-180, RN-70). El
 * estado y la URL firmada se consultan con `GET /documents/{document}`.
 */
class ClinicalRecordCopyController extends Controller
{
    public function __construct(private ClinicalRecordCopyService $copies) {}

    public function store(Request $request, Patient $patient): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $this->copies->request($patient, $user);

        return response()->json(['data' => [
            'id' => $document->uuid,
            'kind' => $document->kind,
            /** @var 'pendiente'|'generando'|'listo'|'fallido' */
            'status' => $document->status,
            'url' => null,
        ]], 201);
    }
}
