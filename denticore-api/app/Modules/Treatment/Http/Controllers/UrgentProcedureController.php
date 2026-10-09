<?php

namespace App\Modules\Treatment\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Treatment\Http\Requests\PlanItemsRequest;
use App\Modules\Treatment\Http\Resources\PerformedProcedureResource;
use App\Modules\Treatment\Services\UrgentProcedureService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Procedimiento de urgencia sin plan previo (SDD §4.3, §5.5; CUS-39 FA-1; RF-012, RF-128): plan,
 * presupuesto, aceptación presencial y procedimiento en una sola transacción, en la atención
 * abierta del odontólogo.
 */
class UrgentProcedureController extends Controller
{
    public function __construct(private UrgentProcedureService $urgent) {}

    #[ProblemResponse(409, 'La atención está cerrada')]
    #[ProblemResponse(422, 'Procedimiento, pieza o documento del firmante inválidos, sin consentimiento informado (RN-76) o pieza ausente; no se registra nada (RF-012)')]
    public function store(Request $request, Attention $attention): JsonResponse
    {
        Gate::authorize('write', $attention);
        /** @var User $dentist */
        $dentist = $request->user();

        /** @var array{procedure_id: string, tooth?: int|null, surfaces?: list<string>|null, quantity?: int, observations?: string|null, signer: 'titular'|'representante', signer_document_number: string} $data */
        $data = $request->validate([
            ...PlanItemsRequest::itemRules(''),
            'observations' => ['nullable', 'string', 'max:1000'],
            'signer' => ['required', Rule::in(['titular', 'representante'])],
            'signer_document_number' => ['required', 'string', 'max:12'],
        ], [], [
            ...PlanItemsRequest::itemAttributes(''),
            'signer' => 'quién acepta',
            'signer_document_number' => 'documento de quien acepta',
        ]);

        $performed = $this->urgent->perform($attention, $data, $dentist, $request->ip(), $request->userAgent());

        return PerformedProcedureResource::make($performed->load(PerformedProcedureResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }
}
