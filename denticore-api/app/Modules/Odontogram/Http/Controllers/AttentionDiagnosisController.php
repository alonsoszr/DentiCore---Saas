<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\AttentionDiagnosisResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\AttentionDiagnosis;
use App\Modules\Odontogram\Services\ClinicalNoteService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Diagnósticos CIE-10 de la nota (SDD §4.3; CUS-80; RF-084, RF-085). Con la atención cerrada,
 * los diagnósticos nuevos se agregan con una adenda (RN-78).
 */
class AttentionDiagnosisController extends Controller
{
    public function __construct(private ClinicalNoteService $notes) {}

    #[ProblemResponse(409, 'La atención está cerrada: use una adenda (RN-78)')]
    #[ProblemResponse(422, 'Sin consentimiento vigente (RN-10) u odontólogo sin COP (RN-75)')]
    public function store(Request $request, Attention $attention): JsonResponse
    {
        Gate::authorize('write', $attention);
        /** @var User $author */
        $author = $request->user();

        /** @var array{cie10_code: string, type: string} $data */
        $data = $request->validate([
            'cie10_code' => ['required', 'string', 'max:7', Rule::exists('cie10_codes', 'code')->where('is_active', true)],
            'type' => ['required', Rule::in(['presuntivo', 'definitivo'])],
        ], [], ['cie10_code' => 'código CIE-10', 'type' => 'tipo de diagnóstico']);

        $diagnosis = $this->notes->addDiagnosis($attention, $data['cie10_code'], $data['type'], $author);

        return AttentionDiagnosisResource::make($diagnosis->load('cie10'))->response()->setStatusCode(201);
    }

    #[ProblemResponse(409, 'La atención está cerrada (RN-78)')]
    public function destroy(Attention $attention, AttentionDiagnosis $diagnosis): Response
    {
        Gate::authorize('write', $attention);

        $this->notes->removeDiagnosis($attention, $diagnosis);

        return response()->noContent();
    }
}
