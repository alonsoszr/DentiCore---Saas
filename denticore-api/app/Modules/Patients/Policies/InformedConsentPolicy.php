<?php

namespace App\Modules\Patients\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Treatment\Models\PlanItem;

/**
 * Consentimiento informado de procedimientos (SDD §3.4, CUS-83): el personal de la clínica firma
 * y revoca los consentimientos informados de los ítems de sus planes (RN-06, RN-76).
 */
class InformedConsentPolicy
{
    private const STAFF = ['clinic_admin', 'dentist', 'receptionist'];

    public function create(User $user, PlanItem $item): bool
    {
        return $this->isStaffOf($user, $item->tenant_id);
    }

    public function revoke(User $user, InformedConsent $consent): bool
    {
        return $this->isStaffOf($user, $consent->tenant_id);
    }

    private function isStaffOf(User $user, int $tenantId): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $tenantId && in_array($user->role, self::STAFF, true);
    }
}
