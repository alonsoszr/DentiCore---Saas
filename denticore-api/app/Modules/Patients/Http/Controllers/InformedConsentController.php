<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Resources\InformedConsentResource;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Services\InformedConsentService;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Consentimiento informado de procedimientos (SDD §4.3.3; CUS-83, RF-073, RF-074, RN-12,
 * RN-76). El ítem del plan y el consentimiento se resuelven con el Global Scope de la clínica
 * (otra clínica → 404).
 */
class InformedConsentController extends Controller
{
    public function __construct(private InformedConsentService $consents) {}

    /**
     * RF-073: versión vigente de la plantilla activa del procedimiento completada con los datos
     * del paciente, el ítem y los riesgos/alternativas escritos por la recepción.
     */
    #[ProblemResponse(422, 'El procedimiento no tiene una única plantilla activa (RF-073) o el odontólogo indicado no está activo')]
    public function preview(Request $request, PlanItem $item): JsonResponse
    {
        Gate::authorize('create', [InformedConsent::class, $item]);

        /** @var array{riesgos?: string|null, alternativas?: string|null, informed_by?: string|null} $query */
        $query = $request->validate([
            'riesgos' => ['nullable', 'string', 'max:500'],
            'alternativas' => ['nullable', 'string', 'max:1000'],
            'informed_by' => ['nullable', 'uuid'],
        ], [], ['informed_by' => 'odontólogo que informa']);

        $preview = $this->consents->preview($item, $request->user(), [
            'riesgos' => (string) ($query['riesgos'] ?? ''),
            'alternativas' => (string) ($query['alternativas'] ?? ''),
        ], $query['informed_by'] ?? null);
        $representative = $preview['representative'];
        $informedBy = $preview['informed_by'];

        return response()->json(['data' => [
            'template_version' => $preview['template_version'],
            'text' => $preview['text'],
            'text_sha256' => $preview['text_sha256'],
            'signer' => $preview['signer'],
            'representative' => $representative === null ? null : [
                'id' => $representative->uuid,
                'first_name' => $representative->first_name,
                'last_name' => $representative->last_name,
                /** @var 'madre'|'padre'|'tutor'|'curador'|'otro' */
                'relationship' => $representative->relationship,
            ],
            'informed_by' => $informedBy === null ? null : [
                'id' => $informedBy->uuid,
                'name' => $informedBy->name,
            ],
        ]]);
    }

    /**
     * RF-073: presencial (dispositivo) confirma el documento del titular o del representante
     * (FE-2); en papel se adjunta el formulario firmado (FA-2). La recepción indica el
     * odontólogo que informa.
     */
    #[ProblemResponse(422, 'Paciente menor sin representante legal vigente (RN-12)')]
    #[ProblemResponse(422, 'El procedimiento no tiene una única plantilla activa (RF-073)')]
    public function store(Request $request, PlanItem $item): JsonResponse
    {
        Gate::authorize('create', [InformedConsent::class, $item]);

        /** @var array{channel: string, confirmation_document_number?: string|null, scanned_file?: UploadedFile|null, informed_by?: string|null, riesgos?: string|null, alternativas?: string|null} $data */
        $data = $request->validate([
            'channel' => ['required', Rule::in(['dispositivo', 'papel'])],
            'confirmation_document_number' => ['exclude_unless:channel,dispositivo', 'required', 'string', 'max:12'],
            'scanned_file' => ['exclude_unless:channel,papel', 'required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'informed_by' => ['nullable', 'uuid'],
            'riesgos' => ['nullable', 'string', 'max:500'],
            'alternativas' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'channel' => 'canal',
            'confirmation_document_number' => 'documento de confirmación',
            'scanned_file' => 'formulario firmado',
            'informed_by' => 'odontólogo que informa',
        ]);

        $consent = $this->consents->sign($item, $data, $request->user(), $request->ip());

        return InformedConsentResource::make($consent)->response()->setStatusCode(201);
    }

    /**
     * RF-074: solo se revoca un consentimiento vigente; la segunda revocación es 409.
     */
    #[ProblemResponse(409, 'El consentimiento informado ya no está vigente (RF-074)')]
    #[ProblemResponse(422, 'Falta el motivo (RF-074)')]
    public function revoke(Request $request, InformedConsent $informedConsent): JsonResponse
    {
        Gate::authorize('revoke', $informedConsent);

        /** @var array{reason: string} $data */
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $consent = $this->consents->revoke($informedConsent, $data['reason']);

        return InformedConsentResource::make($consent)->response()->setStatusCode(200);
    }
}
