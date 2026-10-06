<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\AttentionResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Services\AttentionService;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Atenciones (SDD §4.3; CUS-21, CUS-25, CUS-26). El paciente y la atención se resuelven con el
 * Global Scope de la clínica (otra clínica → 404).
 */
class AttentionController extends Controller
{
    public function __construct(private AttentionService $attentions) {}

    /**
     * CUS-21 (RF-076): atenciones del paciente, de la más reciente a la más antigua.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return AttentionResource::collection(
            Attention::query()
                ->where('patient_id', $patient->id)
                ->with(['patient', 'dentist', 'signer'])
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * CUS-25 (RF-082): abre la atención del odontólogo autenticado. Sin consentimiento vigente
     * se abre igual, con `consent_warning` (RN-10, RN-15): los registros clínicos quedan
     * bloqueados hasta que se otorgue.
     */
    #[ProblemResponse(409, 'El paciente ya tiene una atención abierta con el odontólogo (RF-082)')]
    #[ProblemResponse(422, 'Ficha bloqueada (RF-184) o fusionada (RF-075); odontólogo sin COP (RN-75)')]
    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('create', [Attention::class, $patient]);
        /** @var User $dentist */
        $dentist = $request->user();

        $opened = $this->attentions->open($patient, $dentist);

        return AttentionResource::make($opened['attention']->load(['patient', 'dentist', 'signer']))
            ->additional([
                /** @var array{rule: 'RN-10'|'RN-15', detail: string}|null */
                'consent_warning' => $opened['consent_warning'],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Attention $attention): AttentionResource
    {
        Gate::authorize('view', $attention);

        return AttentionResource::make($attention->load([
            'patient', 'dentist', 'signer', 'note',
            'diagnoses' => fn ($query) => $query->with('cie10')->orderBy('id'),
            'addenda' => fn ($query) => $query->with(['author', 'diagnoses.cie10'])->orderBy('id'),
        ]));
    }

    /**
     * CUS-26 (RF-094): cierra y firma la atención; solo el odontólogo a cargo.
     */
    #[ProblemResponse(409, 'La atención ya está cerrada (RF-094)')]
    #[ProblemResponse(422, 'Falta el motivo de consulta o un diagnóstico CIE-10 (RN-77)')]
    public function close(Request $request, Attention $attention): AttentionResource
    {
        Gate::authorize('close', $attention);
        /** @var User $signer */
        $signer = $request->user();

        return AttentionResource::make($this->attentions->close($attention, $signer)->load(['patient', 'dentist', 'signer']));
    }
}
