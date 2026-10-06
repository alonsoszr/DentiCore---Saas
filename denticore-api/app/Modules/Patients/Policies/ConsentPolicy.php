<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;

/**
 * Consentimiento de datos (SDD §3.4, CUS-17): el personal de la clínica (Administrador,
 * Odontólogo y Recepcionista) registra y consulta los consentimientos de sus pacientes. El
 * portal (PA) llega con las rutas PORT de M10.
 */
class ConsentPolicy
{
    private const STAFF = ['clinic_admin', 'dentist', 'receptionist'];

    public function create(User $user, Patient $patient): bool
    {
        return $this->isStaffOf($user, $patient->tenant_id);
    }

    public function view(User $user, Consent $consent): bool
    {
        return $this->isStaffOf($user, $consent->tenant_id);
    }

    private function isStaffOf(User $user, int $tenantId): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $tenantId && in_array($user->role, self::STAFF, true);
    }
}
