<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Services\PortalSubjectResolver;
use App\Modules\Treatment\Http\Resources\BudgetResource;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Services\BudgetDecisionService;
use App\Modules\Treatment\Services\BudgetPdfLink;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Presupuestos en el portal (SDD §4.3.10, §5.4.3; CUS-36, CUS-37; RF-121 a RF-123, RN-36). El
 * paciente ve sus presupuestos emitidos o decididos (un borrador es interno de la clínica) y decide
 * sobre el emitido con su sesión: el canal es `portal` y firma la cuenta, titular adulto o
 * representante (`portal.adult`). `portal.subject` da 404 para otro paciente (CA-37.5).
 */
class PortalBudgetController extends Controller
{
    public function __construct(private BudgetDecisionService $decisions, private PortalSubjectResolver $subjects) {}

    /**
     * RF-121: los presupuestos del paciente, del más reciente al más antiguo.
     */
    public function index(Patient $patient): AnonymousResourceCollection
    {
        return BudgetResource::collection(
            Budget::query()
                ->where('patient_id', $patient->id)
                ->where('status', '<>', 'borrador')
                ->with(BudgetResource::RELATIONS)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function show(Budget $budget): BudgetResource
    {
        abort_if($budget->status === 'borrador', 404);

        return BudgetResource::make($budget->load(BudgetResource::RELATIONS));
    }

    /**
     * RF-121: estado del PDF y su URL firmada de 10 minutos.
     */
    public function pdf(Request $request, Budget $budget, BudgetPdfLink $pdfLink): JsonResponse
    {
        abort_if($budget->status === 'borrador', 404);

        return response()->json(['data' => $pdfLink->for($budget, $request->user())]);
    }

    /**
     * CUS-37 desde el portal: aceptar en su totalidad o rechazar con un motivo opcional.
     */
    #[ProblemResponse(403, 'La decisión corresponde al representante legal (RN-12)')]
    #[ProblemResponse(409, 'El presupuesto ya fue decidido, venció o su plan no admite la aceptación (RN-35, RN-36)')]
    public function decide(Request $request, Budget $budget): BudgetResource
    {
        abort_if($budget->status === 'borrador', 404);
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{decision: 'aceptado'|'rechazado', rejection_reason?: string|null, rejection_detail?: string|null} $data */
        $data = $request->validate([
            'decision' => ['required', Rule::in(['aceptado', 'rechazado'])],
            ...BudgetDecisionService::REJECTION_RULES,
        ], [], BudgetDecisionService::ATTRIBUTES);

        $signer = $this->subjects->isHolder($actor, $budget->patient) ? 'titular' : 'representante';
        $budget = $this->decisions->decide(
            $budget,
            [...$data, 'channel' => 'portal', 'signer' => $signer],
            $actor,
            $request->ip(),
            $request->userAgent(),
        );

        return BudgetResource::make($budget->load(BudgetResource::RELATIONS));
    }
}
