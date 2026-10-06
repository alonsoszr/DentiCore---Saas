<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Odontogram\Services\OdontogramEntryService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Registrar hallazgos en el odontograma (SDD §4.3, §5.3; CUS-22; RF-087 a RF-091). La API solo
 * acepta códigos del catálogo NTS 188, nunca procedimientos (RN-25).
 */
class OdontogramEntryController extends Controller
{
    public function __construct(private OdontogramEntryService $entries) {}

    #[ProblemResponse(409, 'La atención está cerrada; abra una nueva atención (RF-087)')]
    #[ProblemResponse(422, 'Pieza, superficie o hallazgo inválidos (RN-16 a RN-18, RN-25); sin consentimiento (RN-10); sin COP (RN-75)')]
    public function store(Request $request, Attention $attention): JsonResponse
    {
        Gate::authorize('create', [OdontogramEntry::class, $attention]);
        /** @var User $author */
        $author = $request->user();

        /** @var array{tooth: int, tooth_end?: int|null, surfaces?: list<string>|null, finding_code: string, state_code: string, note?: string|null} $data */
        $data = $request->validate([
            'tooth' => ['required', 'integer'],
            'tooth_end' => ['nullable', 'integer'],
            'surfaces' => ['nullable', 'array', 'max:7'],
            'surfaces.*' => ['string', 'size:1'],
            'finding_code' => ['required', 'string', 'max:20'],
            'state_code' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'tooth' => 'pieza',
            'tooth_end' => 'pieza final',
            'surfaces' => 'superficies',
            'finding_code' => 'hallazgo',
            'state_code' => 'estado',
            'note' => 'nota',
        ]);

        $entry = $this->entries->record($attention, $data, $author);

        return OdontogramEntryResource::make($entry->load(['finding', 'findingState', 'author']))->response()->setStatusCode(201);
    }
}
