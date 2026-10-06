<?php

namespace App\Modules\Treatment\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Models\Procedure;

/**
 * Autorización por registro sobre el catálogo de procedimientos (SDD §3.4; CUS-32): solo el
 * Administrador de Clínica lo modifica; el personal lo consulta (rol en la ruta).
 */
class ProcedurePolicy
{
    public function create(User $user): bool
    {
        return $user->role === 'clinic_admin' && $user->tenant_id !== null;
    }

    public function update(User $user, Procedure $procedure): bool
    {
        return $user->role === 'clinic_admin' && $user->tenant_id === $procedure->tenant_id;
    }

    public function delete(User $user, Procedure $procedure): bool
    {
        return $this->update($user, $procedure);
    }
}
