<?php

namespace App\Modules\Patients\Http\Middleware;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentGate;
use App\Support\Http\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `consent:<finalidad>` (SDD §4.2; RN-10, RN-14, RN-53, RN-58, CA-64.3): el
 * `{patient}` de la ruta necesita un consentimiento vigente con la finalidad y no estar
 * bloqueado ni fusionado. Responde 422 `RN-10`, 403 `RN-53` (IA) o 422 `RN-58` (predicción).
 */
class EnsurePatientConsent
{
    private const RULES = [
        'ia' => ['RN-53', 403, 'El paciente no otorgó la finalidad de asistencia de IA generativa.'],
        'prediccion' => ['RN-58', 422, 'El paciente no otorgó la finalidad de predicción de riesgo.'],
    ];

    public function __construct(private ConsentGate $gate) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws BusinessRuleException
     */
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        if (! $this->gate->allows($this->patient($request), $purpose)) {
            [$rule, $status, $detail] = self::RULES[$purpose]
                ?? ['RN-10', 422, 'El paciente no tiene un consentimiento de datos vigente para esta finalidad.'];

            throw new BusinessRuleException($rule, $detail, status: $status);
        }

        return $next($request);
    }

    /**
     * El `{patient}` ya enlazado o su uuid; el Global Scope limita la búsqueda a la clínica.
     */
    private function patient(Request $request): Patient
    {
        $patient = $request->route('patient');

        if ($patient instanceof Patient) {
            return $patient;
        }

        abort_unless(is_string($patient) && Str::isUuid($patient), 404);

        return Patient::query()->where('uuid', $patient)->firstOrFail();
    }
}
