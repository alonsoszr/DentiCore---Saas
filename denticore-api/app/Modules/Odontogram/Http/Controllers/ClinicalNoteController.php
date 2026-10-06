<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Http\Resources\ClinicalNoteResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Services\ClinicalNoteService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Nota de atención (SDD §4.3; CUS-80; RF-084). El autoguardado cada 30 s (RF-086) llega en
 * MS-15; la SPA guarda con este mismo PUT idempotente.
 */
class ClinicalNoteController extends Controller
{
    public function __construct(private ClinicalNoteService $notes) {}

    #[ProblemResponse(409, 'La atención está cerrada o la nota firmada: use una adenda (RN-78)')]
    #[ProblemResponse(422, 'Sin consentimiento vigente (RN-10) u odontólogo sin COP (RN-75)')]
    public function update(Request $request, Attention $attention): JsonResponse
    {
        Gate::authorize('write', $attention);

        /** @var array{chief_complaint?: string|null, current_illness?: string|null, extraoral_exam?: string|null, intraoral_exam?: string|null, indications?: string|null} $sections */
        $sections = $request->validate([
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'current_illness' => ['nullable', 'string', 'max:5000'],
            'extraoral_exam' => ['nullable', 'string', 'max:5000'],
            'intraoral_exam' => ['nullable', 'string', 'max:5000'],
            'indications' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'chief_complaint' => 'motivo de consulta',
            'current_illness' => 'enfermedad actual',
            'extraoral_exam' => 'examen extraoral',
            'intraoral_exam' => 'examen intraoral',
            'indications' => 'indicaciones',
        ]);

        // PUT idempotente: 200 también cuando la nota se crea con el primer guardado.
        return ClinicalNoteResource::make($this->notes->save($attention, $sections))->response()->setStatusCode(200);
    }
}
