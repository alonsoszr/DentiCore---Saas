<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;

/**
 * Autorización por registro sobre la ficha del paciente (SDD §3.2 capa 3). La rama del rol
 * `patient` es heredada: TASK-089 la retira cuando exista el portal (DI-13).
 */
class PatientPolicy
{
    /**
     * Personal de la clínica: cualquier ficha de su propia clínica. `patient`: solo la
     * ficha vinculada a su cuenta (patient.user_id = auth()->id()). super_admin no
     * accede a datos clínicos.
     */
    public function view(User $user, Patient $patient): bool
    {
        if ($user->tenant_id === null || $user->tenant_id !== $patient->tenant_id) {
            return false;
        }

        if ($user->role === 'patient') {
            return $patient->user_id === $user->id;
        }

        return in_array($user->role, ['clinic_admin', 'dentist', 'receptionist'], true);
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
