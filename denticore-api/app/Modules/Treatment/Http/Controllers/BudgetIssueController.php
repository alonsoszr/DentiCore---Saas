<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Http\Resources\BudgetResource;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Services\BudgetIssuer;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Emitir el presupuesto (SDD §4.3, §5.4.2; CUS-35; RF-116, RF-118, RN-32, RN-33, RN-35, DD-23).
 */
class BudgetIssueController extends Controller
{
    public function __construct(private BudgetIssuer $issuer) {}

    #[ProblemResponse(409, 'El presupuesto ya fue emitido (RN-34)')]
    #[ProblemResponse(422, 'Procedimientos inactivos en el catálogo, con la lista de líneas (RN-32)')]
    public function store(Request $request, Budget $budget): BudgetResource
    {
        Gate::authorize('issue', $budget);
        /** @var User $actor */
        $actor = $request->user();

        return BudgetResource::make($this->issuer->issue($budget, $actor)->load(BudgetResource::RELATIONS));
    }
}
