<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Http\Resources\AttentionAddendumResource;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Services\AddendumService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adendas (SDD §4.3; CUS-81; RF-097): información posterior al cierre, con diagnósticos
 * opcionales. Una adenda con motivo y diagnóstico completa una atención `cerrada_incompleta`.
 */
class AttentionAddendumController extends Controller
{
    public function __construct(private AddendumService $addenda) {}

    #[ProblemResponse(409, 'La atención sigue abierta: use la nota (RN-78)')]
    #[ProblemResponse(422, 'Odontólogo sin COP (RN-75)')]
    public function store(Request $request, Attention $attention): JsonResponse
    {
        Gate::authorize('addendum', $attention);
        /** @var User $author */
        $author = $request->user();

        /** @var array{text: string, chief_complaint?: string|null, diagnoses?: list<array{cie10_code: string, type: string}>} $data */
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'diagnoses' => ['nullable', 'array', 'max:20'],
            // Mismas reglas que un diagnóstico de la nota (RF-085).
            'diagnoses.*.cie10_code' => ['required', 'string', 'max:7', Rule::exists('cie10_codes', 'code')->where('is_active', true)],
            'diagnoses.*.type' => ['required', Rule::in(['presuntivo', 'definitivo'])],
        ], [], [
            'text' => 'texto de la adenda',
            'chief_complaint' => 'motivo de consulta',
            'diagnoses.*.cie10_code' => 'código CIE-10',
            'diagnoses.*.type' => 'tipo de diagnóstico',
        ]);

        $addendum = $this->addenda->add($attention, $data, $author);

        return AttentionAddendumResource::make($addendum->load(['author', 'attention', 'diagnoses.cie10']))
            ->response()
            ->setStatusCode(201);
    }
}
