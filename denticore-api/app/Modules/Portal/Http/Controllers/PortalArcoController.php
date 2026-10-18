<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Compliance\Http\Resources\ArcoRequestResource;
use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoRequestService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Solicitudes ARCO desde el portal (SDD §4.3.10; CUS-63, RF-182): la sesión verifica la identidad
 * del titular o del representante (SRS §11.15, precondición 2). `portal.subject` da 404 para otro
 * paciente.
 */
class PortalArcoController extends Controller
{
    public function __construct(private ArcoRequestService $requests) {}

    public function index(Patient $patient): AnonymousResourceCollection
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

    public function store(Request $request, Patient $patient): JsonResponse
    {
        /** @var array{type: string, description: string} $data */
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(ArcoRequest::TYPES)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], ['type' => 'tipo', 'description' => 'descripción']);
        /** @var User $user */
        $user = $request->user();

        return ArcoRequestResource::make($this->requests->registerFromPortal($patient, $data, $user))->response()->setStatusCode(201);
    }
}
