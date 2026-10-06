<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Resources\LegalRepresentativeResource;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Representantes legales de un paciente (SDD §4.3.3; CUS-16, RF-059 a RF-061). El paciente se
 * resuelve con el Global Scope de la clínica (otra clínica → 404).
 */
class LegalRepresentativeController extends Controller
{
    public function __construct(private LegalRepresentativeService $representatives) {}

    public function index(Patient $patient): AnonymousResourceCollection
    {
        return LegalRepresentativeResource::collection(
            $patient->representatives()->orderByRaw('valid_until IS NOT NULL')->orderByDesc('valid_from')->get(),
        );
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        /** @var array{document_type: string, document_number: string, first_name: string, last_name: string, relationship: string, phone: string, email?: string|null, valid_from: string} $data */
        $data = $request->validate(LegalRepresentativeService::rules(), [], [
            'document_type' => 'tipo de documento',
            'document_number' => 'número de documento',
            'relationship' => 'parentesco',
            'phone' => 'teléfono',
            'valid_from' => 'inicio de la vigencia',
        ]);

        return LegalRepresentativeResource::make($this->representatives->add($patient, $data))->response()->setStatusCode(201);
    }

    /**
     * RF-061: termina una representación vigente con su motivo.
     */
    public function end(Request $request, Patient $patient, string $representative): LegalRepresentativeResource
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', Rule::in(['revocada', 'otro'])]], [], ['reason' => 'motivo']);

        /** @var LegalRepresentative $current */
        $current = $patient->representatives()->where('uuid', $representative)->whereNull('valid_until')->firstOrFail();

        return LegalRepresentativeResource::make($this->representatives->end($current, $data['reason']));
    }
}
