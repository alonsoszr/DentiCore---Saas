<?php

namespace App\Support\Audit;

/**
 * Modelos que aparecen como recurso en la bitácora (SDD §1.3 `Auditable`). Un modelo ligado
 * a un paciente devuelve su uuid para filtrar la bitácora por paciente sin guardar datos
 * clínicos (columna `audit_logs.patient_uuid`).
 */
trait Auditable
{
    public function auditPatientUuid(): ?string
    {
        return null;
    }
}
