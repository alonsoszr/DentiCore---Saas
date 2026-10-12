<?php

namespace App\Modules\Risk\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Models\RiskAlert;
use Illuminate\Auth\Access\Response;

/**
 * Reconocimiento de alertas de riesgo (SDD §3.4; CUS-58; RN-65): solo el odontólogo destinatario.
 * Una alerta ajena responde 404, para no revelar que existe.
 */
class RiskAlertPolicy
{
    public function acknowledge(User $user, RiskAlert $alert): Response
    {
        return $alert->dentist_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
