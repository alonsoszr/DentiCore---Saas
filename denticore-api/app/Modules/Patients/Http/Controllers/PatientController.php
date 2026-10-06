<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Http\Requests\StorePatientRequest;
use App\Modules\Patients\Http\Requests\UpdatePatientRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientIdentity;
use App\Modules\Patients\Services\PatientService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Ficha del paciente (M03). index/store con `role:` y show con can:view,patient
 * (PatientPolicy) en la ruta.
 */
class PatientController extends Controller
{
    public function __construct(private PatientService $patients) {}

    /**
     * CUS-13 (RF-054, RF-010): búsqueda por nombre o apellido, filtro de estado de archivo y
     * `per_page` hasta 100.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array{q?: string|null, archive_status?: string|null, per_page?: int|null} $filters */
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'archive_status' => ['nullable', Rule::in(['activo', 'pasivo', 'bloqueado', 'apto_eliminacion', 'fusionado'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PatientResource::collection($this->patients->paginate($filters));
    }

    /**
     * CUS-13, CUS-14 (RF-056): ficha por tipo y número de documento (índice ciego); 404 si no
     * existe en la clínica.
     */
    public function lookup(Request $request): PatientResource
    {
        /** @var array{document_type: string, document_number: string} $data */
        $data = $request->validate([
            'document_type' => ['required', Rule::in(array_keys(PatientIdentity::DOCUMENT_PREFIXES))],
            'document_number' => ['required', 'string', 'max:12'],
        ]);

        $patient = $this->patients->lookup(TenantContext::tenantOrFail(), $data['document_type'], $data['document_number']);
        abort_if($patient === null, 404);

        return PatientResource::make($patient);
    }

    #[ProblemResponse(422, 'El documento ya está registrado: incluye existing_patient_id (RF-056)')]
    public function store(StorePatientRequest $request): JsonResponse
    {
        /** @var array{document_type: string, document_number: string, first_name: string, last_name: string, birth_date: string, sex: string, phone: string, email?: string|null, address?: string|null, representative?: array<string, mixed>|null, user_uuid?: string|null} $data */
        $data = $request->validated();
        /** @var User $creator */
        $creator = $request->user();

        $patient = $this->patients->register(TenantContext::tenantOrFail(), $data, $creator);

        return PatientResource::make($patient)->response()->setStatusCode(201);
    }

    public function show(Patient $patient): PatientResource
    {
        return PatientResource::make($patient->load(['user', 'currentConsent']));
    }

    /**
     * CUS-15 (RF-062): identificación y contacto, con historial cifrado de los valores previos.
     */
    #[ProblemResponse(422, 'El documento ya está registrado en otra ficha (RN-09)')]
    public function update(UpdatePatientRequest $request, Patient $patient): PatientResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return PatientResource::make($this->patients->updateIdentity($patient, $request->validated(), $actor));
    }
}
