<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tokens\OneTimeToken;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\DB;

/**
 * Recuperación de contraseña (CUS-09; RF-039, DD-15): enlace de un solo uso válido 60 minutos a
 * `/c/:slug/restablecer/:token` (SDD §1.10). La solicitud responde igual exista o no el correo.
 * Al restablecer se revocan todas las sesiones.
 */
class PasswordResetService
{
    public const VALID_MINUTES = 60;

    public function __construct(
        private OneTimeTokenService $tokens,
        private NotificationService $notifications,
        private PasswordService $passwords,
        private AuditLogger $audit,
    ) {}

    /**
     * Envía el enlace solo si el usuario existe y está activo o bloqueado; si no, no hace nada
     * (la respuesta HTTP es la misma).
     */
    public function requestLink(Tenant $tenant, string $email): void
    {
        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', mb_strtolower(trim($email)))
            ->whereIn('status', ['activo', 'bloqueado_temporal'])
            ->first();

        if ($user === null) {
            return;
        }

        DB::transaction(function () use ($tenant, $user): void {
            $this->tokens->invalidateFor($user, TokenPurpose::PasswordReset);
            ['token' => $token] = $this->tokens->issue($user, TokenPurpose::PasswordReset, now()->addMinutes(self::VALID_MINUTES));

            $this->notifications->sendEmail(
                NotificationEvent::RestablecimientoContrasena,
                $user,
                links: ['reset' => config('app.spa_url')."/c/{$tenant->slug}/restablecer/{$token}"],
            );
        });
    }

    public function reset(OneTimeToken $token, string $password): void
    {
        DB::transaction(function () use ($token, $password): void {
            /** @var User $user */
            $user = User::query()->findOrFail($token->tokenable_id);

            $this->passwords->set($user, $password);
            $this->tokens->consume($token);

            $this->audit->record(AuditEvent::AuthPasswordChanged, $user, actor: $user);
        });
    }
}
