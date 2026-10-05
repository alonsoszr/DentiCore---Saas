<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Antecedentes médicos (SDD §4.3.3, §2.14.1; CUS-14, CUS-21; RF-064). La ruta lleva
 * `consent:atencion`: sin consentimiento vigente responde 422 `RN-10` (CA-14.5).
 */
class MedicalHistoryController extends Controller
{
    public function __construct(private PatientService $patients) {}

    /**
     * Reemplaza los antecedentes: exactamente `alergias`, `enfermedades`, `medicamentos` y
     * `observaciones`; listas de hasta 30 elementos de hasta 150 caracteres.
     */
    #[ProblemResponse(422, 'Sin consentimiento vigente para la atención (RN-10)')]
    public function update(Request $request, Patient $patient): PatientResource
    {
        $unexpected = array_diff(array_keys($request->all()), Patient::MEDICAL_HISTORY_KEYS);

        if ($unexpected !== []) {
            throw ValidationException::withMessages(
                collect($unexpected)->mapWithKeys(fn (string $key) => [$key => 'Los antecedentes solo admiten alergias, enfermedades, medicamentos y observaciones.'])->all(),
            );
        }

        /** @var array{alergias: list<string|null>, enfermedades: list<string|null>, medicamentos: list<string|null>, observaciones: string|null} $data */
        $data = $request->validate([
            'alergias' => ['present', 'array', 'list', 'max:30'],
            'alergias.*' => ['nullable', 'string', 'max:150'],
            'enfermedades' => ['present', 'array', 'list', 'max:30'],
            'enfermedades.*' => ['nullable', 'string', 'max:150'],
            'medicamentos' => ['present', 'array', 'list', 'max:30'],
            'medicamentos.*' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['present', 'nullable', 'string', 'max:2000'],
        ]);

        return PatientResource::make($this->patients->updateMedicalHistory($patient, $data)->load(['user', 'currentConsent']));
    }
}
