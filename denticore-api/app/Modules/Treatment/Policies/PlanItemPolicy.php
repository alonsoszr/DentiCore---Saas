<?php

namespace App\Modules\Treatment\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Models\PlanItem;

/**
 * Autorización por registro sobre los ítems del plan (SDD §3.4; CUS-40): el odontólogo y el
 * Administrador de Clínica descartan ítems. Editar o quitar un ítem es editar su plan
 * (`TreatmentPlanPolicy@update`).
 */
class PlanItemPolicy
{
    public function discard(User $user, PlanItem $item): bool
    {
        return in_array($user->role, ['clinic_admin', 'dentist'], true) && $user->tenant_id === $item->tenant_id;
    }
}
