<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Services\BudgetIssuer;
use App\Support\Files\FileStorage;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PDF del presupuesto (SDD §4.3; CUS-35, CUS-36; RF-118, RF-121; DD-18): estado de la generación
 * y, cuando está listo, su URL firmada de 10 minutos.
 */
class BudgetDocumentController extends Controller
{
    public function __construct(private BudgetIssuer $issuer, private FileStorage $files) {}

    public function show(Request $request, Budget $budget): JsonResponse
    {
        Gate::authorize('view', $budget);

        $document = $budget->pdfDocument;
        $file = $document?->status === 'listo' ? $document->storedFile : null;

        return response()->json(['data' => [
            /** @var 'pendiente'|'generando'|'listo'|'fallido'|null */
            'status' => $document?->status,
            'url' => $file === null ? null : $this->files->temporaryUrl($file, $request->user()),
        ]]);
    }

    /**
     * FE-4 de CUS-35: tras fallar los 3 intentos, se vuelve a pedir el PDF.
     */
    #[ProblemResponse(409, 'El PDF no falló o el presupuesto es un borrador (RF-118)')]
    public function regenerate(Request $request, Budget $budget): JsonResponse
    {
        Gate::authorize('issue', $budget);
        /** @var User $actor */
        $actor = $request->user();

        $document = $this->issuer->regeneratePdf($budget, $actor);

        return response()->json(['data' => [
            /** @var 'pendiente'|'generando'|'listo'|'fallido' */
            'status' => $document->status,
            'url' => null,
        ]], 202);
    }
}
