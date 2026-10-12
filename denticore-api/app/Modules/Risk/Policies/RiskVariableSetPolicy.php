<?php

namespace App\Modules\Risk\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Models\RiskVariableSet;

/**
 * Registro de variables de riesgo por responsable (SDD §3.4, §3.5; CUS-54; RF-162): la recepción
 * escribe solo la sección sociodemográfica y el odontólogo las clínicas y conductuales.
 */
class RiskVariableSetPolicy
{
    private const SECTIONS_BY_ROLE = [
        'dentist' => ['clinical', 'behavioral'],
        'receptionist' => ['sociodemographic'],
    ];

    /**
     * @param  list<string>  $sections
     */
    public function create(User $user, array $sections): bool
    {
        $allowed = self::SECTIONS_BY_ROLE[$user->role] ?? [];

        return $sections !== [] && array_diff($sections, $allowed) === [];
    }

    /**
     * @param  list<string>  $sections
     */
    public function update(User $user, RiskVariableSet $set, array $sections): bool
    {
        return $user->tenant_id === $set->tenant_id && $this->create($user, $sections);
    }
}
