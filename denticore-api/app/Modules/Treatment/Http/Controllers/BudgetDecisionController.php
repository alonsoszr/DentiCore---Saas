<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Http\Resources\BudgetResource;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Services\BudgetDecisionService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Registrar la decisión presencial sobre el presupuesto (SDD §4.3, §5.4.3; CUS-37; RF-122,
 * RF-123, RN-35 a RN-37). La del portal está en `PortalBudgetController`; la del enlace es de E2.
 */
class BudgetDecisionController extends Controller
{
    public function __construct(private BudgetDecisionService $decisions) {}

    #[ProblemResponse(403, 'El paciente es menor: decide su representante legal (RN-12)')]
    #[ProblemResponse(409, 'El presupuesto ya fue decidido, venció o su plan no admite la aceptación (RN-35, RN-36)')]
    #[ProblemResponse(422, 'Documento del firmante que no coincide con la ficha o datos de la decisión inválidos (RF-122)')]
    public function store(Request $request, Budget $budget): BudgetResource
    {
        Gate::authorize('decide', $budget);
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{decision: 'aceptado'|'rechazado', signer: 'titular'|'representante', signer_document_number: string, rejection_reason?: string|null, rejection_detail?: string|null, signed_file?: UploadedFile|null} $data */
        $data = $request->validate([
            'decision' => ['required', Rule::in(['aceptado', 'rechazado'])],
            'signer' => ['required', Rule::in(['titular', 'representante'])],
            'signer_document_number' => ['required', 'string', 'max:12'],
            ...BudgetDecisionService::REJECTION_RULES,
            'signed_file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ], [], BudgetDecisionService::ATTRIBUTES);

        $budget = $this->decisions->decide($budget, $data, $actor, $request->ip(), $request->userAgent());

        return BudgetResource::make($budget->load(BudgetResource::RELATIONS));
    }
}
