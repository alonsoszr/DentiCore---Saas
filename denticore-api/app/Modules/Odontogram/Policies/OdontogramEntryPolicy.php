<?php

namespace App\Modules\Odontogram\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;

/**
 * Autorización por registro sobre las entradas del odontograma (SDD §3.4; CUS-22, CUS-23, CUS-34).
 */
class OdontogramEntryPolicy
{
    /**
     * CUS-22 (RN-19): el odontólogo a cargo de la atención.
     */
    public function create(User $user, Attention $attention): bool
    {
        return $user->role === 'dentist' && $user->id === $attention->dentist_id;
    }

    /**
     * CUS-23: cualquier odontólogo de la clínica; también la entrada de otro (SRS §11.6 FA-3).
     */
    public function correct(User $user, OdontogramEntry $entry): bool
    {
        return $user->role === 'dentist' && $user->tenant_id !== null && $user->tenant_id === $entry->tenant_id;
    }

    /**
     * CUS-34 (RF-113): cualquier odontólogo de la clínica.
     */
    public function decideNoTreat(User $user, OdontogramEntry $entry): bool
    {
        return $user->role === 'dentist' && $user->tenant_id !== null && $user->tenant_id === $entry->tenant_id;
    }
}
