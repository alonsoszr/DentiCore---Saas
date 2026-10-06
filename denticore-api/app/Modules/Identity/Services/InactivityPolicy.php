<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Vencimiento por inactividad (SDD §1.7; DD-15, RF-036, CA-06.5): 30 minutos para el personal y
 * 15 para el portal (`patient`). Se evalúa en `Sanctum::authenticateAccessTokensUsing`, antes de
 * que Sanctum actualice `last_used_at`.
 */
final class InactivityPolicy
{
    public const STAFF_MINUTES = 30;

    public const PORTAL_MINUTES = 15;

    public static function expired(PersonalAccessToken $token): bool
    {
        $lastActivity = $token->last_used_at ?? $token->created_at;

        if ($lastActivity === null) {
            return false;
        }

        $tokenable = $token->tokenable;
        $minutes = $tokenable instanceof User && $tokenable->role === 'patient' ? self::PORTAL_MINUTES : self::STAFF_MINUTES;

        return $lastActivity->lt(now()->subMinutes($minutes));
    }
}
