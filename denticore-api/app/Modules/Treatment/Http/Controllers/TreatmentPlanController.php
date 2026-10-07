<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Http\Requests\TreatmentPlanRequest;
use App\Modules\Treatment\Http\Resources\TreatmentPlanResource;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Modules\Treatment\Services\TreatmentPlanService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Plan de tratamiento (SDD §4.3; CUS-33, CUS-40; RF-110, RF-114, RF-129, RF-130). El plan y el
 * paciente se resuelven con el Global Scope de la clínica (otra clínica → 404).
 */
class TreatmentPlanController extends Controller
{
    public function __construct(private TreatmentPlanService $plans) {}

    /**
     * RF-130: los planes del paciente, del más reciente al más antiguo, con su avance.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return TreatmentPlanResource::collection(
            TreatmentPlan::query()
                ->where('patient_id', $patient->id)
                ->with(TreatmentPlanResource::RELATIONS)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    #[ProblemResponse(422, 'Procedimiento inactivo, pieza o superficies inválidas para el procedimiento o hallazgo que no es rojo vigente (RN-26, RN-27)')]
    public function store(TreatmentPlanRequest $request, Patient $patient): JsonResponse
    {
        Gate::authorize('create', [TreatmentPlan::class, $patient]);
        /** @var User $author */
        $author = $request->user();

        /** @var array{title: string, items?: list<array{procedure_id: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, session_number?: int|null, observations?: string|null, finding_ids?: list<string>}>} $data */
        $data = $request->validated();
        $plan = $this->plans->create($patient, $data, $author);

        return TreatmentPlanResource::make($plan->load(TreatmentPlanResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TreatmentPlan $plan): TreatmentPlanResource
    {
        return TreatmentPlanResource::make($plan->load(TreatmentPlanResource::RELATIONS));
    }

    #[ProblemResponse(409, 'El plan no está en borrador (RF-114)')]
    public function update(TreatmentPlanRequest $request, TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('update', $plan);

        /** @var array{title?: string} $data */
        $data = $request->validated();

        return TreatmentPlanResource::make($this->plans->update($plan, $data)->load(TreatmentPlanResource::RELATIONS));
    }

    /**
     * Presentar el plan al paciente: borrador → propuesto (SRS §5.5.2).
     */
    #[ProblemResponse(409, 'Transición no definida en SRS §5.5.2 (RF-114)')]
    #[ProblemResponse(422, 'El plan no tiene ítems propuestos (RF-114)')]
    public function propose(TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('update', $plan);

        return TreatmentPlanResource::make($this->plans->propose($plan)->load(TreatmentPlanResource::RELATIONS));
    }

    /**
     * Volver a editar: propuesto → borrador, sin presupuesto emitido vigente (SRS §5.5.2).
     */
    #[ProblemResponse(409, 'Transición no definida o presupuesto emitido vigente (RF-114)')]
    public function reopen(TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('update', $plan);

        return TreatmentPlanResource::make($this->plans->reopen($plan)->load(TreatmentPlanResource::RELATIONS));
    }

    /**
     * RF-129: ítems y valor realizados frente al monto aceptado antes de cancelar.
     */
    #[ProblemResponse(409, 'El plan ya está completado o cancelado (RF-114)')]
    public function cancellationPreview(TreatmentPlan $plan): JsonResponse
    {
        Gate::authorize('cancel', $plan);

        $preview = $this->plans->cancellationPreview($plan);

        return response()->json(['data' => [
            'items_total' => $preview['items_total'],
            'items_performed' => $preview['items_performed'],
            'performed_amount' => $preview['performed_amount'],
            'accepted_amount' => $preview['accepted_amount'],
        ]]);
    }

    #[ProblemResponse(409, 'El plan ya está completado o cancelado (RF-114)')]
    #[ProblemResponse(422, 'Falta el motivo (RF-129)')]
    public function cancel(Request $request, TreatmentPlan $plan): TreatmentPlanResource
    {
        Gate::authorize('cancel', $plan);

        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);

        return TreatmentPlanResource::make($this->plans->cancel($plan, $data['reason'])->load(TreatmentPlanResource::RELATIONS));
    }
}
