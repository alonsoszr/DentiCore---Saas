<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\FindingNoTreatDecisionResource;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\NoTreatDecisionService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Decidir no tratar un hallazgo rojo (SDD §4.3; CUS-34; RF-113, RN-27).
 */
class NoTreatDecisionController extends Controller
{
    public function __construct(private NoTreatDecisionService $decisions) {}

    #[ProblemResponse(409, 'El hallazgo ya tiene una decisión o un ítem del plan (RN-27)')]
    #[ProblemResponse(422, 'Motivo de menos de 10 caracteres (RF-113) o hallazgo que no es rojo vigente (RN-27)')]
    public function store(Request $request, OdontogramEntry $entry): JsonResponse
    {
        Gate::authorize('decideNoTreat', $entry);
        /** @var User $author */
        $author = $request->user();

        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], [], ['reason' => 'motivo']);

        $decision = $this->decisions->record($entry, $data['reason'], $author);

        return FindingNoTreatDecisionResource::make($decision->load('decider'))
            ->response()
            ->setStatusCode(201);
    }
}
