<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Requests\StorePatientRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Ficha del paciente (M03). index/store con `role:` y show con can:view,patient
 * (PatientPolicy) en la ruta.
 */
class PatientController extends Controller
{
    public function __construct(private PatientService $patients) {}

    public function index(): AnonymousResourceCollection
    {
        return PatientResource::collection($this->patients->paginate());
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $patient = $this->patients->create(TenantContext::tenantOrFail(), $request->validated());

        return PatientResource::make($patient)->response()->setStatusCode(201);
    }

    public function show(Patient $patient): PatientResource
    {
        return PatientResource::make($patient->load('user'));
    }
}
