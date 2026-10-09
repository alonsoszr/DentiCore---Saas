<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Treatment\Http\Resources\PerformedProcedureResource;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Services\PerformedProcedureService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registrar un procedimiento realizado (SDD §4.3, §5.5; CUS-39; RF-126, RF-127, RF-130). El
 * odontólogo lo registra en su atención abierta del paciente del plan.
 */
class PerformedProcedureController extends Controller
{
    public function __construct(private PerformedProcedureService $procedures) {}

    #[ProblemResponse(409, 'El ítem no pertenece a un presupuesto aceptado (RN-38) o la atención está cerrada')]
    #[ProblemResponse(422, 'Cantidad mayor que la pendiente, sin consentimiento informado (RN-76), pieza ausente o atención de otro paciente')]
    public function store(Request $request, PlanItem $item): JsonResponse
    {
        Gate::authorize('perform', $item);
        /** @var User $dentist */
        $dentist = $request->user();

        /** @var array{attention_id: string, quantity?: int, observations?: string|null} $data */
        $data = $request->validate([
            'attention_id' => ['required', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'between:1,32'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ], [], ['attention_id' => 'atención', 'quantity' => 'cantidad', 'observations' => 'observaciones']);

        $attention = Attention::query()->where('uuid', $data['attention_id'])->first()
            ?? throw ValidationException::withMessages(['attention_id' => 'La atención no existe.']);
        Gate::authorize('write', $attention);

        $performed = $this->procedures->record($item, $attention, $data['quantity'] ?? 1, $data['observations'] ?? null, $dentist);

        return PerformedProcedureResource::make($performed->load(PerformedProcedureResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }
}
