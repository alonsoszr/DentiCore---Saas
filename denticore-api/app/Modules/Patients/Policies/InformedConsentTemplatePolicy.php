<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsentTemplate;

/**
 * Plantillas de consentimiento informado (SDD §3.4, CUS-82): el personal de la clínica consulta
 * las plantillas; solo el Administrador las crea, edita o desactiva (SDD §4.2).
 */
class InformedConsentTemplatePolicy
{
    private const STAFF = ['clinic_admin', 'dentist', 'receptionist'];

    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, InformedConsentTemplate $template): bool
    {
        return $this->manage($user, $template);
    }

    public function manage(User $user, InformedConsentTemplate $template): bool
    {
        return $user->tenant_id !== null
            && $user->tenant_id === $template->tenant_id
            && $user->role === 'clinic_admin';
    }

    private function isStaff(User $user): bool
    {
        return $user->tenant_id !== null && in_array($user->role, self::STAFF, true);
    }

    private function isAdmin(User $user): bool
    {
        return $user->tenant_id !== null && $user->role === 'clinic_admin';
    }
}
