<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StorePatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * technical_specs.md §5.3: ficha del paciente. index/store con EnsureRole y show con
 * can:view,patient (PatientPolicy) en la ruta.
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
        $patient = $this->patients->create(app('currentTenant'), $request->validated());

        return PatientResource::make($patient)->response()->setStatusCode(201);
    }

    public function show(Patient $patient): PatientResource
    {
        return PatientResource::make($patient->load('user'));
    }
}
