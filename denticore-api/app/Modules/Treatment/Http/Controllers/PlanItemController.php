<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Treatment\Http\Requests\PlanItemRequest;
use App\Modules\Treatment\Http\Requests\PlanItemsRequest;
use App\Modules\Treatment\Http\Resources\PlanItemResource;
use App\Modules\Treatment\Http\Resources\TreatmentPlanResource;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Modules\Treatment\Services\TreatmentPlanService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Ítems del plan de tratamiento (SDD §4.3; CUS-33, CUS-40; RF-110, RF-111, RF-114, RF-129).
 */
class PlanItemController extends Controller
{
    public function __construct(private TreatmentPlanService $plans) {}

    /**
     * RF-110, RF-111: agrega ítems al plan en borrador, opcionalmente desde hallazgos rojos.
     */
    #[ProblemResponse(409, 'El plan no está en borrador (RF-114)')]
    #[ProblemResponse(422, 'Procedimiento inactivo, pieza o superficies inválidas para el procedimiento o hallazgo que no es rojo vigente (RN-26, RN-27)')]
    public function store(PlanItemsRequest $request, TreatmentPlan $plan): JsonResponse
    {
        Gate::authorize('update', $plan);

        /** @var array{items: list<array{procedure_id: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, session_number?: int|null, observations?: string|null, finding_ids?: list<string>}>} $data */
        $data = $request->validated();

        return TreatmentPlanResource::make($this->plans->addItems($plan, $data['items'])->load(TreatmentPlanResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    #[ProblemResponse(409, 'El plan no está en borrador o el ítem no está propuesto (RF-114)')]
    #[ProblemResponse(422, 'Procedimiento inactivo o pieza y superficies inválidas para el procedimiento (RN-26)')]
    public function update(PlanItemRequest $request, PlanItem $item): PlanItemResource
    {
        Gate::authorize('update', $item->plan);

        /** @var array{procedure_id?: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, session_number?: int|null, observations?: string|null} $data */
        $data = $request->validated();

        return PlanItemResource::make($this->plans->updateItem($item, $data)->load(['procedure', 'findings']));
    }

    #[ProblemResponse(409, 'El plan no está en borrador, el ítem no está propuesto o figura en un presupuesto (RF-114)')]
    public function destroy(PlanItem $item): Response
    {
        Gate::authorize('update', $item->plan);

        $this->plans->deleteItem($item);

        return response()->noContent();
    }

    /**
     * CUS-40 (RF-129): descarta el ítem con motivo.
     */
    #[ProblemResponse(409, 'Transición no definida del ítem o plan completado o cancelado (RF-114)')]
    #[ProblemResponse(422, 'Falta el motivo (RF-129)')]
    public function discard(Request $request, PlanItem $item): PlanItemResource
    {
        Gate::authorize('discard', $item);

        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);

        return PlanItemResource::make($this->plans->discardItem($item, $data['reason'])->load(['procedure', 'findings']));
    }
}
