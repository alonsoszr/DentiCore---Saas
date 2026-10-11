<?php

namespace App\Modules\Scheduling\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\ScheduleBlock;

/**
 * Autorización por registro de los bloqueos de agenda (SDD §3.4; CUS-44): el Administrador de
 * Clínica bloquea a cualquier odontólogo o a toda la clínica; el odontólogo, solo su propia agenda.
 */
class ScheduleBlockPolicy
{
    public function create(User $user, ?User $dentist): bool
    {
        return match ($user->role) {
            'clinic_admin' => true,
            'dentist' => $dentist !== null && $dentist->id === $user->id,
            default => false,
        };
    }

    public function delete(User $user, ScheduleBlock $block): bool
    {
        return match ($user->role) {
            'clinic_admin' => $user->tenant_id === $block->tenant_id,
            'dentist' => $block->dentist_id === $user->id,
            default => false,
        };
    }
}
