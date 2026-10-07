<?php

namespace App\Modules\Treatment\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Treatment\Models\TreatmentPlan;

/**
 * Autorización por registro sobre el plan de tratamiento (SDD §3.4): el odontólogo lo elabora
 * (CUS-33); el odontólogo y el Administrador de Clínica lo cancelan (CUS-40). La consulta la
 * limita el rol en la ruta.
 */
class TreatmentPlanPolicy
{
    public function create(User $user, Patient $patient): bool
    {
        return $user->role === 'dentist' && $user->tenant_id === $patient->tenant_id;
    }

    public function update(User $user, TreatmentPlan $plan): bool
    {
        return $user->role === 'dentist' && $user->tenant_id === $plan->tenant_id;
    }

    public function cancel(User $user, TreatmentPlan $plan): bool
    {
        return in_array($user->role, ['clinic_admin', 'dentist'], true) && $user->tenant_id === $plan->tenant_id;
    }
}
