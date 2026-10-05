<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Resources\ConsentResource;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentRenderer;
use App\Modules\Patients\Services\ConsentService;
use App\Support\Files\FileStorage;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Consentimiento de datos (SDD §4.3.3; CUS-17; RF-047, RF-065, RF-066, RF-067). El paciente y
 * el consentimiento se resuelven con el Global Scope de la clínica (otra clínica → 404).
 */
class ConsentController extends Controller
{
    public function __construct(private ConsentService $consents, private ConsentRenderer $renderer) {}

    /**
     * RF-065, RF-067: consentimientos del paciente, del más reciente al más antiguo.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('create', [Consent::class, $patient]);

        return ConsentResource::collection(
            $patient->consents()->with('legalRepresentative')->orderByDesc('granted_at')->orderByDesc('id')->get(),
        );
    }

    /**
     * CUS-17 pasos 2–3: texto de la versión vigente completado (DD-28), quién lo otorga y las
     * finalidades que la clínica puede ofrecer; las opcionales se presentan sin marcar (CA-17.1).
     */
    #[ProblemResponse(422, 'Paciente menor sin representante legal vigente (RN-12)')]
    public function preview(Patient $patient): JsonResponse
    {
        Gate::authorize('create', [Consent::class, $patient]);

        $preview = $this->consents->preview($patient);
        $representative = $preview['representative'];

        return response()->json(['data' => [
            /** @var int */
            'template_version' => $preview['template_version'],
            'text' => $preview['text'],
            'text_sha256' => $preview['text_sha256'],
            'granted_by' => $preview['granted_by'],
            'representative' => $representative === null ? null : [
                'id' => $representative->uuid,
                'first_name' => $representative->first_name,
                'last_name' => $representative->last_name,
                /** @var 'madre'|'padre'|'tutor'|'curador'|'otro' */
                'relationship' => $representative->relationship,
            ],
            /** @var list<'purpose_care'|'purpose_notifications'|'purpose_ai'|'purpose_risk'|'purpose_surveys'> */
            'available_purposes' => $preview['available_purposes'],
        ]]);
    }

    /**
     * CUS-17 pasos 4–8: la finalidad (a) es obligatoria (RN-10); (c) y (d) solo si la clínica las
     * ofrece (CA-17.5). Presencial: confirmación con el documento del titular o del representante
     * (FE-2). Papel: formulario firmado escaneado (FA-2).
     */
    #[ProblemResponse(422, 'Paciente menor sin representante legal vigente (RN-12)')]
    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('create', [Consent::class, $patient]);

        /** @var array{channel: string, purpose_notifications?: bool|null, purpose_ai?: bool|null, purpose_risk?: bool|null, purpose_surveys?: bool|null, confirmation_document_number?: string|null, scanned_file?: UploadedFile|null} $data */
        $data = $request->validate([
            'channel' => ['required', Rule::in(['presencial', 'papel'])],
            'purpose_care' => ['required', 'accepted'],
            'purpose_notifications' => ['nullable', 'boolean'],
            'purpose_ai' => ['nullable', 'boolean'],
            'purpose_risk' => ['nullable', 'boolean'],
            'purpose_surveys' => ['nullable', 'boolean'],
            'confirmation_document_number' => ['exclude_unless:channel,presencial', 'required', 'string', 'max:12'],
            'scanned_file' => ['exclude_unless:channel,papel', 'required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'purpose_care.accepted' => 'La finalidad (a) atención odontológica es obligatoria.',
            'purpose_care.required' => 'La finalidad (a) atención odontológica es obligatoria.',
        ], [
            'channel' => 'canal',
            'confirmation_document_number' => 'documento de confirmación',
            'scanned_file' => 'formulario firmado',
        ]);

        $this->ensurePurposesAreOffered($patient, $data);

        $consent = $this->consents->grant($patient, $data, $request->user(), $request->ip());

        return ConsentResource::make($consent)->response()->setStatusCode(201);
    }

    /**
     * CA-17.5: (c) y (d) solo se otorgan si la clínica las ofrece (plan e IA activada).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function ensurePurposesAreOffered(Patient $patient, array $data): void
    {
        $available = $this->renderer->availablePurposes($patient->tenant);
        $unavailable = collect(['purpose_ai', 'purpose_risk'])
            ->filter(fn (string $column) => filter_var($data[$column] ?? false, FILTER_VALIDATE_BOOLEAN) && ! in_array($column, $available, true));

        if ($unavailable->isNotEmpty()) {
            throw ValidationException::withMessages(
                $unavailable->mapWithKeys(fn (string $column) => [$column => 'La clínica no ofrece esta finalidad.'])->all(),
            );
        }
    }

    /**
     * RF-066: estado de la constancia PDF y, cuando está lista, su URL firmada de 10 minutos.
     */
    public function certificate(Request $request, Consent $consent): JsonResponse
    {
        Gate::authorize('view', $consent);

        $document = $consent->certificate;
        $file = $document?->status === 'listo' ? $document->storedFile : null;

        return response()->json(['data' => [
            /** @var 'pendiente'|'generando'|'listo'|'fallido'|null */
            'status' => $document?->status,
            'url' => $file === null ? null : app(FileStorage::class)->temporaryUrl($file, $request->user()),
        ]]);
    }
}
