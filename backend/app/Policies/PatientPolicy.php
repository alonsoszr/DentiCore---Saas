<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * Autorización fina sobre la ficha del paciente (technical_specs.md §4.2).
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
}
