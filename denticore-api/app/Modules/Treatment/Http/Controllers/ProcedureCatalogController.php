<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Treatment\Http\Requests\ProcedureRequest;
use App\Modules\Treatment\Http\Resources\ProcedureResource;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Services\ProcedureCatalogService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Catálogo de procedimientos de la clínica (SDD §4.3; CUS-32; RF-107, RF-109). El procedimiento
 * se resuelve con el Global Scope de la clínica (otra clínica → 404).
 */
class ProcedureCatalogController extends Controller
{
    public function __construct(private ProcedureCatalogService $procedures) {}

    /**
     * RF-107: el catálogo completo, activos e inactivos, por categoría y nombre.
     */
    public function index(): AnonymousResourceCollection
    {
        return ProcedureResource::collection(
            Procedure::query()
                ->with(['resultingFinding', 'resultingFindingState'])
                ->orderBy('category')
                ->orderBy('name')
                ->get(),
        );
    }

    #[ProblemResponse(422, 'Código repetido en la clínica, precio fuera de rango o hallazgo resultante inválido (RF-107)')]
    public function store(ProcedureRequest $request): JsonResponse
    {
        Gate::authorize('create', Procedure::class);

        $procedure = $this->procedures->create($request->validated());

        return ProcedureResource::make($procedure->load(['resultingFinding', 'resultingFindingState']))
            ->response()
            ->setStatusCode(201);
    }

    #[ProblemResponse(422, 'Código repetido en la clínica, precio fuera de rango o hallazgo resultante inválido (RF-107)')]
    public function update(ProcedureRequest $request, Procedure $procedure): ProcedureResource
    {
        Gate::authorize('update', $procedure);

        return ProcedureResource::make(
            $this->procedures->update($procedure, $request->validated())->load(['resultingFinding', 'resultingFindingState']),
        );
    }

    /**
     * RF-109: solo un procedimiento sin uso se elimina; el usado se desactiva con PATCH.
     */
    #[ProblemResponse(409, 'El procedimiento se usó en planes o presupuestos: solo puede desactivarse (RF-109)')]
    public function destroy(Procedure $procedure): Response
    {
        Gate::authorize('delete', $procedure);

        $this->procedures->delete($procedure);

        return response()->noContent();
    }
}
