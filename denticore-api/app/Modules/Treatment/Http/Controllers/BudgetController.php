<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Http\Resources\BudgetResource;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Modules\Treatment\Services\BudgetService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Presupuestos (SDD §4.3; CUS-35, CUS-36; RF-115, RF-119, RF-120, RF-121). El presupuesto, el
 * plan y el paciente se resuelven con el Global Scope de la clínica (otra clínica → 404).
 */
class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    /**
     * RF-121: los presupuestos del paciente, del más reciente al más antiguo.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return BudgetResource::collection(
            Budget::query()
                ->where('patient_id', $patient->id)
                ->with(BudgetResource::RELATIONS)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * CUS-35 pasos 1 y 2 (RN-28): borrador con una línea por ítem propuesto o por los indicados en
     * `plan_item_ids`; el precio es el vigente del catálogo.
     */
    #[ProblemResponse(409, 'El plan no está propuesto (RN-28)')]
    #[ProblemResponse(422, 'El plan no tiene ítems propuestos o un ítem indicado no lo está (RN-28)')]
    public function store(Request $request, TreatmentPlan $plan): JsonResponse
    {
        Gate::authorize('create', [Budget::class, $plan]);

        /** @var array{plan_item_ids?: list<string>} $data */
        $data = $request->validate([
            'plan_item_ids' => ['sometimes', 'array', 'min:1'],
            'plan_item_ids.*' => ['string', 'uuid', 'distinct'],
        ], [], ['plan_item_ids' => 'ítems del plan']);

        $budget = $this->budgets->createDraft($plan, $data['plan_item_ids'] ?? null);

        return BudgetResource::make($budget->load(BudgetResource::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(Budget $budget): BudgetResource
    {
        Gate::authorize('view', $budget);

        return BudgetResource::make($budget->load(BudgetResource::RELATIONS));
    }

    /**
     * Descartar un borrador; uno emitido no se elimina (RN-34).
     */
    #[ProblemResponse(409, 'El presupuesto emitido no se puede modificar; use Corregir (RN-34)')]
    public function destroy(Budget $budget): Response
    {
        Gate::authorize('update', $budget);

        $this->budgets->delete($budget);

        return response()->noContent();
    }

    /**
     * RF-119: borrador nuevo con las mismas líneas que referencia al emitido.
     */
    #[ProblemResponse(409, 'Solo se corrige un presupuesto emitido de un plan propuesto (RF-119)')]
    #[ProblemResponse(422, 'Un ítem del presupuesto ya no está propuesto (RN-28)')]
    public function correct(Budget $budget): JsonResponse
    {
        Gate::authorize('create', [Budget::class, $budget->plan]);

        return BudgetResource::make($this->budgets->correct($budget)->load(BudgetResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }
}
