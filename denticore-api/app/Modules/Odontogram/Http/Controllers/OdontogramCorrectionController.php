<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\OdontogramCorrectionService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Registrar una corrección (SDD §4.3, §5.3; CUS-23; RF-093): anulación o reemplazo con motivo.
 */
class OdontogramCorrectionController extends Controller
{
    public function __construct(private OdontogramCorrectionService $corrections) {}

    #[ProblemResponse(409, 'La entrada ya tiene una corrección; corrija la corrección (RN-23)')]
    #[ProblemResponse(422, 'Motivo de menos de 10 caracteres o datos de reemplazo inválidos (RN-23); sin COP (RN-75)')]
    public function store(Request $request, OdontogramEntry $entry): JsonResponse
    {
        Gate::authorize('correct', $entry);
        /** @var User $author */
        $author = $request->user();

        /** @var array{kind: 'anulacion'|'reemplazo', reason: string, tooth?: int|null, tooth_end?: int|null, surfaces?: list<string>|null, finding_code?: string|null, state_code?: string|null} $data */
        $data = $request->validate([
            'kind' => ['required', Rule::in(['anulacion', 'reemplazo'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            // Datos de reemplazo: las mismas validaciones que CUS-22 (SRS §11.6).
            'tooth' => ['exclude_unless:kind,reemplazo', 'required', 'integer'],
            'tooth_end' => ['exclude_unless:kind,reemplazo', 'nullable', 'integer'],
            'surfaces' => ['exclude_unless:kind,reemplazo', 'nullable', 'array', 'max:7'],
            'surfaces.*' => ['exclude_unless:kind,reemplazo', 'string', 'size:1'],
            'finding_code' => ['exclude_unless:kind,reemplazo', 'required', 'string', 'max:20'],
            'state_code' => ['exclude_unless:kind,reemplazo', 'required', 'string', 'max:20'],
        ], [], [
            'kind' => 'tipo de corrección',
            'reason' => 'motivo',
            'tooth' => 'pieza',
            'tooth_end' => 'pieza final',
            'surfaces' => 'superficies',
            'finding_code' => 'hallazgo',
            'state_code' => 'estado',
        ]);

        $correction = $this->corrections->correct($entry, $data, $author);

        return OdontogramEntryResource::make($correction->load(['finding', 'findingState', 'author', 'correctedEntry']))
            ->response()
            ->setStatusCode(201);
    }
}
