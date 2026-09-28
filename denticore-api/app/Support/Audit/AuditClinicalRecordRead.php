<?php

namespace App\Support\Audit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra cada lectura de historia clínica con `clinical_record.viewed` (SDD §5.14; RN-67,
 * RNF-105). Se aplica a las rutas de consulta de HC; solo audita respuestas exitosas.
 */
class AuditClinicalRecordRead
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $patient = $request->route('patient');

        if ($response->isSuccessful() && $patient instanceof Model) {
            $this->audit->record(AuditEvent::ClinicalRecordViewed, $patient);
        }

        return $response;
    }
}
