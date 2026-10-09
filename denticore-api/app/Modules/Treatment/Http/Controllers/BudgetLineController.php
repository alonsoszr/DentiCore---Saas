<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Http\Requests\BudgetLineRequest;
use App\Modules\Treatment\Http\Resources\BudgetResource;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\BudgetLine;
use App\Modules\Treatment\Services\BudgetService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Descuento por línea del borrador (SDD §4.3, §5.4.2 paso 2; CUS-35; RF-115, RF-120, RN-31).
 */
class BudgetLineController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    /**
     * Devuelve el presupuesto con los totales recalculados.
     */
    #[ProblemResponse(403, 'Descuento sobre el tope de la clínica sin ser Administrador de Clínica (RN-31)')]
    #[ProblemResponse(409, 'El presupuesto emitido no se puede modificar; use Corregir (RN-34)')]
    #[ProblemResponse(422, 'Descuento fuera de 0 a 100 o sin motivo de 5 a 200 caracteres (RN-31)')]
    public function update(BudgetLineRequest $request, Budget $budget, BudgetLine $line): BudgetResource
    {
        Gate::authorize('update', $budget);
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{discount_pct: int|float|string, discount_reason?: string|null} $data */
        $data = $request->validated();

        return BudgetResource::make($this->budgets->updateLine($budget, $line, $data, $actor)->load(BudgetResource::RELATIONS));
    }
}
