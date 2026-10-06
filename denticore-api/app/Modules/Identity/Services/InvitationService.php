<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\DB;

/**
 * Invitación de activación de cuenta (DD-22, RF-016, RF-042): enlace de un solo uso válido
 * 72 horas a `/c/:slug/activar/:token` (SDD §1.10). Emitir una invitación nueva invalida la
 * anterior. El correo sale por el outbox en la transacción del llamador.
 */
class InvitationService
{
    public const VALID_HOURS = 72;

    public function __construct(
        private OneTimeTokenService $tokens,
        private NotificationService $notifications,
    ) {}

    public function send(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->loadMissing('tenant');
            $slug = $user->tenant?->slug;

            $this->tokens->invalidateFor($user, TokenPurpose::Invitation);
            ['token' => $token] = $this->tokens->issue($user, TokenPurpose::Invitation, now()->addHours(self::VALID_HOURS));

            $link = config('app.spa_url').($slug === null ? '' : "/c/{$slug}")."/activar/{$token}";

            $send = fn () => $this->notifications->sendEmail(NotificationEvent::InvitacionActivacion, $user, links: ['activate' => $link]);

            $user->tenant === null ? $send() : TenantContext::run($user->tenant, $send);
        });
    }
}
