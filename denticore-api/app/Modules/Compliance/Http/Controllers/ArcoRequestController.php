<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Http\Requests\ResolveArcoRequestRequest;
use App\Modules\Compliance\Http\Requests\StoreArcoRequestRequest;
use App\Modules\Compliance\Http\Resources\ArcoRequestResource;
use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoRequestService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes ARCO (SDD §4.3.11). En la clínica, el registro de la recepción o del administrador
 * (CUS-63, RF-182); la bandeja y la atención, solo del Oficial de Datos Personales (CUS-64, RF-183;
 * `role:clinic_admin` + `data.officer`).
 */
class ArcoRequestController extends Controller
{
    private const DETAIL_RELATIONS = ['patient', 'legalRepresentative', 'assignedDentist', 'responseDocument'];

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

    /**
     * CUS-64, pasos 1 y 2: bandeja de la clínica ordenada por fecha límite, con el titular y los
     * días hábiles que faltan (DD-26). Filtros opcionales por estado y tipo.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array{status?: string|null, type?: string|null} $filters */
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['recibida', 'en_tramite', 'atendida', 'denegada'])],
            'type' => ['nullable', Rule::in(ArcoRequest::TYPES)],
        ]);

        return ArcoRequestResource::collection(
            ArcoRequest::query()
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
                ->with(self::DETAIL_RELATIONS)
                ->withCount('files')
                ->orderBy('due_date')
                ->orderBy('received_at')
                ->orderBy('id')
                ->get(),
        );
    }

    public function show(ArcoRequest $arcoRequest): ArcoRequestResource
    {
        return ArcoRequestResource::make($arcoRequest->load(self::DETAIL_RELATIONS)->loadCount('files'));
    }

    /**
     * CUS-64, paso 3: la solicitud pasa a `en_tramite` a cargo del Oficial.
     */
    #[ProblemResponse(409, 'La solicitud ya está en trámite o resuelta (RN-69)')]
    public function start(Request $request, ArcoRequest $arcoRequest): ArcoRequestResource
    {
        /** @var User $officer */
        $officer = $request->user();

        return ArcoRequestResource::make($this->requests->start($arcoRequest, $officer)->load(self::DETAIL_RELATIONS));
    }

    /**
     * CUS-64, paso 4: la rectificación de datos clínicos la ejecuta un odontólogo (CUS-23).
     */
    #[ProblemResponse(409, 'La solicitud no está en trámite (RN-69)')]
    #[ProblemResponse(422, 'Solo una rectificación se asigna a un odontólogo (RN-69)')]
    public function assignCorrection(Request $request, ArcoRequest $arcoRequest): ArcoRequestResource
    {
        /** @var array{dentist_id: string} $data */
        $data = $request->validate(['dentist_id' => ['required', 'uuid']], [], ['dentist_id' => 'odontólogo']);
        // User no lleva el Global Scope de la clínica: se acota aquí (RN-03).
        /** @var User $dentist */
        $dentist = User::query()
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('uuid', $data['dentist_id'])
            ->firstOr(fn () => throw ValidationException::withMessages(['dentist_id' => ['Indica un odontólogo activo de la clínica.']]));

        return ArcoRequestResource::make($this->requests->assignCorrection($arcoRequest, $dentist)->load(self::DETAIL_RELATIONS));
    }

    /**
     * CUS-64, pasos 5 a 8: acción del tipo, respuesta al titular e indicador de plazo.
     */
    #[ProblemResponse(409, 'La solicitud no está en trámite (RN-69)')]
    #[ProblemResponse(422, 'Cancelación sin aplicar (RF-184) o paciente sin consentimiento vigente (RN-14)')]
    public function resolve(ResolveArcoRequestRequest $request, ArcoRequest $arcoRequest): ArcoRequestResource
    {
        /** @var array{status: string, response: string, denial_reason?: string|null, purposes?: list<string>, correction_entry_id?: string|null} $data */
        $data = $request->validated();
        /** @var User $officer */
        $officer = $request->user();

        return ArcoRequestResource::make($this->requests->resolve($arcoRequest, $data, $officer)->load(self::DETAIL_RELATIONS));
    }
}
