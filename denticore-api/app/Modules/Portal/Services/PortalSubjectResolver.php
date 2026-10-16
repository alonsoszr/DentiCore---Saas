<?php

namespace App\Modules\Portal\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Sujetos del portal (SDD §3.3; RF-061, RN-12, RN-13, RN-80): dentro de la clínica del token, la
 * ficha propia de la cuenta y los menores que representa con una representación vigente.
 */
class PortalSubjectResolver
{
    /** Estados de archivo que cierran el portal de la ficha propia (SDD §3.3). */
    private const CLOSED_STATUSES = ['bloqueado', 'fusionado'];

    /**
     * @return Collection<int, Patient> La ficha propia primero; luego los representados.
     */
    public function for(User $user): Collection
    {
        $own = Patient::query()
            ->where('user_id', $user->id)
            ->whereNotIn('archive_status', self::CLOSED_STATUSES)
            ->whereNull('deceased_on')
            ->get();

        // La edad se evalúa a la fecha de la operación: el representado que ya cumplió 18 años
        // deja de verse aunque la tarea diaria aún no cierre la representación (RN-13).
        $represented = Patient::query()
            ->whereHas('representatives', fn ($query) => $query->where('user_id', $user->id)->whereNull('valid_until'))
            ->whereNull('deceased_on')
            ->orderBy('first_name')
            ->get()
            ->filter(fn (Patient $patient): bool => $patient->isMinorOn());

        return $own->concat($represented)->unique('id')->values();
    }

    /**
     * Si el paciente es la ficha propia de la cuenta (titular) y no un representado.
     */
    public function isHolder(User $user, Patient $patient): bool
    {
        return $patient->user_id === $user->id;
    }
}
