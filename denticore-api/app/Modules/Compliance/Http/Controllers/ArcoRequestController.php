<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Http\Requests\StoreArcoRequestRequest;
use App\Modules\Compliance\Http\Resources\ArcoRequestResource;
use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoRequestService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

/**
 * Solicitudes ARCO de un paciente en la clínica (SDD §4.3.11; CUS-63, RF-182). La bandeja y la
 * atención del Oficial de Datos Personales (CUS-64) llegan con TASK-104.
 */
class ArcoRequestController extends Controller
{
    public function __construct(private ArcoRequestService $requests) {}

    public function indexForPatient(Patient $patient): AnonymousResourceCollection
    {
        return ArcoRequestResource::collection(
            ArcoRequest::query()
                ->where('patient_id', $patient->id)
                ->with('legalRepresentative')
                ->withCount('files')
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function store(StoreArcoRequestRequest $request, Patient $patient): JsonResponse
    {
        /** @var array{type: string, requested_by: string, legal_representative_id?: string|null, description: string, confirmation_document_number: string, files?: list<UploadedFile>} $data */
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return ArcoRequestResource::make($this->requests->registerAtClinic($patient, $data, $user))->response()->setStatusCode(201);
    }
}
