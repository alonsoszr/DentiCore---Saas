<?php

namespace App\Modules\Treatment\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\TreatmentPlan;

/**
 * Autorización por registro sobre los presupuestos (SDD §3.4; CUS-35, CUS-36): el personal de la
 * clínica los crea, edita, emite y consulta. El tope de descuento por rol (RN-31) lo aplica
 * BudgetService sobre cada línea. La decisión presencial la registran la recepción y el
 * Administrador de Clínica (CUS-37).
 */
class BudgetPolicy
{
    private const STAFF = ['clinic_admin', 'dentist', 'receptionist'];

    public function create(User $user, TreatmentPlan $plan): bool
    {
        return $this->isStaffOf($user, $plan->tenant_id);
    }

    public function view(User $user, Budget $budget): bool
    {
        return $this->isStaffOf($user, $budget->tenant_id);
    }

    public function update(User $user, Budget $budget): bool
    {
        return $this->isStaffOf($user, $budget->tenant_id);
    }

    public function issue(User $user, Budget $budget): bool
    {
        return $this->isStaffOf($user, $budget->tenant_id);
    }

    public function decide(User $user, Budget $budget): bool
    {
        return in_array($user->role, ['clinic_admin', 'receptionist'], true) && $user->tenant_id === $budget->tenant_id;
    }

    private function isStaffOf(User $user, int $tenantId): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $tenantId && in_array($user->role, self::STAFF, true);
    }
}
