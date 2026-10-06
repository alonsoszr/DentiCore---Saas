<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Http\Resources\OdontogramEntryResource;
use App\Modules\Odontogram\Services\DentitionResolver;
use App\Modules\Odontogram\Services\OdontogramStateService;
use App\Modules\Patients\Models\Patient;
use App\Support\Http\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Odontograma del paciente (SDD §4.3, §5.3; CUS-21; RF-077, RF-079, RF-092, RN-24): estado
 * vigente (o a una fecha) y odontograma inicial, por separado. Lectura auditada (RN-67).
 */
class OdontogramController extends Controller
{
    public function __construct(private OdontogramStateService $odontograms) {}

    /**
     * Estado vigente (RN-24); `at` (fecha ISO 8601) da el estado a esa fecha (RF-080).
     */
    public function current(Request $request, Patient $patient): JsonResponse
    {
        /** @var array{at?: string|null} $data */
        $data = $request->validate(['at' => ['nullable', 'date']], [], ['at' => 'fecha']);
        $at = isset($data['at']) ? CarbonImmutable::parse($data['at'])->utc() : null;

        return response()->json(['data' => [
            'at' => $at,
            'default_dentition' => DentitionResolver::default($patient->ageYears()),
            'entries' => OdontogramEntryResource::collection($this->odontograms->current($patient, $at)),
        ]]);
    }

    /**
     * RF-079: odontograma inicial con sus entradas `inicial` no corregidas; null antes de la
     * primera atención.
     */
    public function initial(Patient $patient): JsonResponse
    {
        $initial = $this->odontograms->initial($patient);

        return response()->json(['data' => $initial === null ? null : [
            /** @var 'abierto'|'cerrado' */
            'status' => $initial['odontogram']->status,
            /** @var string|null */
            'closed_at' => $initial['odontogram']->closed_at,
            /** @var 'cierre_atencion'|'cierre_automatico'|null */
            'closed_by' => $initial['odontogram']->closed_by,
            'entries' => OdontogramEntryResource::collection($initial['entries']),
        ]]);
    }
}
