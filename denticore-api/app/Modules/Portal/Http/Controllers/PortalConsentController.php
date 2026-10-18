<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Http\Resources\ConsentPreviewResource;
use App\Modules\Patients\Http\Resources\ConsentResource;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentRevocationService;
use App\Modules\Patients\Services\ConsentService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Consentimiento de datos desde el portal (SDD §4.3.10; CUS-17 FA-1; RF-065, RF-066). La
 * confirmación es la propia sesión autenticada: el canal es `portal` y no hay personal que asista.
 * El sujeto lo resuelve `portal.subject` (otro paciente → 404) y `portal.adult` decide quién otorga.
 */
class PortalConsentController extends Controller
{
    public function __construct(
        private ConsentService $consents,
        private ConsentRevocationService $revocations,
    ) {}

    /**
     * CUS-18: el titular adulto o el representante revoca finalidades con su sesión; canal `portal`
     * (RF-068, RF-069).
     */
    #[ProblemResponse(403, 'La decisión corresponde al representante legal (RN-12)')]
    #[ProblemResponse(409, 'El consentimiento ya no está vigente (RN-14)')]
    public function revoke(Request $request, Patient $patient, Consent $consent): ConsentResource
    {
        /** @var array{purposes: list<string>, reason?: string|null} $data */
        $data = $request->validate([
            'purposes' => ['required', 'array', 'min:1', 'max:5'],
            'purposes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Consent::PURPOSES))],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['purposes' => 'finalidades', 'purposes.*' => 'finalidad', 'reason' => 'motivo']);
        /** @var User $user */
        $user = $request->user();

        return ConsentResource::make($this->revocations->revoke(
            $patient, $consent, $data['purposes'], $data['reason'] ?? null, $user, 'portal',
        ));
    }

    /**
     * RF-065, RF-067: consentimientos del paciente, del más reciente al más antiguo.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return ConsentResource::collection(
            $patient->consents()->with('legalRepresentative')->orderByDesc('granted_at')->orderByDesc('id')->get(),
        );
    }

    /**
     * CUS-17 pasos 2–3: texto vigente y finalidades; las opcionales se presentan sin marcar (CA-17.1).
     */
    #[ProblemResponse(422, 'Paciente menor sin representante legal vigente (RN-12)')]
    public function preview(Request $request, Patient $patient): ConsentPreviewResource
    {
        /** @var User $user */
        $user = $request->user();

        return ConsentPreviewResource::make($this->consents->preview($patient, $user));
    }

    /**
     * CUS-17 FA-1: la finalidad (a) es obligatoria (RN-10); (c) y (d) solo si la clínica las
     * ofrece (CA-17.5). Lo otorga el titular adulto o su representante vigente (RN-12).
     */
    #[ProblemResponse(403, 'La decisión corresponde al representante legal (RN-12)')]
    public function store(Request $request, Patient $patient): JsonResponse
    {
        /** @var array{purpose_notifications?: bool|null, purpose_ai?: bool|null, purpose_risk?: bool|null, purpose_surveys?: bool|null} $data */
        $data = $request->validate([
            'purpose_care' => ['required', 'accepted'],
            'purpose_notifications' => ['nullable', 'boolean'],
            'purpose_ai' => ['nullable', 'boolean'],
            'purpose_risk' => ['nullable', 'boolean'],
            'purpose_surveys' => ['nullable', 'boolean'],
        ], ConsentService::PURPOSE_CARE_MESSAGES);

        $this->consents->ensurePurposesOffered($patient, $data);

        /** @var User $user */
        $user = $request->user();
        $consent = $this->consents->grant($patient, ['channel' => 'portal', ...$data], $user, $request->ip());

        return ConsentResource::make($consent)->response()->setStatusCode(201);
    }
}
