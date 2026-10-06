<?php

namespace App\Modules\Odontogram\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;

/**
 * Autorización por registro sobre la atención (SDD §3.4; CUS-21, CUS-25, CUS-26, CUS-80, CUS-81).
 */
class AttentionPolicy
{
    /**
     * CUS-21: Administrador de Clínica y Odontólogo de la misma clínica.
     */
    public function view(User $user, Attention $attention): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $attention->tenant_id
            && in_array($user->role, ['clinic_admin', 'dentist'], true);
    }

    /**
     * CUS-25: el odontólogo abre la atención de un paciente de su clínica.
     */
    public function create(User $user, Patient $patient): bool
    {
        return $user->role === 'dentist' && $user->tenant_id !== null && $user->tenant_id === $patient->tenant_id;
    }

    /**
     * CUS-80: nota y diagnósticos, solo en atenciones a cargo del odontólogo (SDD §3.4).
     */
    public function write(User $user, Attention $attention): bool
    {
        return $user->role === 'dentist' && $user->id === $attention->dentist_id;
    }

    /**
     * CUS-81: cualquier odontólogo de la clínica agrega información posterior (RN-78).
     */
    public function addendum(User $user, Attention $attention): bool
    {
        return $user->role === 'dentist' && $user->tenant_id !== null && $user->tenant_id === $attention->tenant_id;
    }

    /**
     * CUS-26: solo el odontólogo a cargo cierra la atención.
     */
    public function close(User $user, Attention $attention): bool
    {
        return $user->role === 'dentist' && $user->id === $attention->dentist_id;
    }
}
