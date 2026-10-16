<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;

/**
 * Autorización por registro sobre la ficha del paciente (SDD §3.2 capa 3). La cuenta del portal
 * (`patient`) no usa las rutas del personal: lee lo suyo en /portal/*, sin notas clínicas (DI-13).
 */
class PatientPolicy
{
    /**
     * Personal de la clínica: cualquier ficha de su propia clínica. super_admin no accede a datos
     * clínicos y la cuenta del portal usa sus propias rutas (TASK-089).
     */
    public function view(User $user, Patient $patient): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $patient->tenant_id
            && in_array($user->role, ['clinic_admin', 'dentist', 'receptionist'], true);
    }

    /**
     * CUS-15: Administrador de Clínica y Recepcionista de la misma clínica (SDD §3.4).
     */
    public function updateIdentity(User $user, Patient $patient): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $patient->tenant_id
            && in_array($user->role, ['clinic_admin', 'receptionist'], true);
    }
}
