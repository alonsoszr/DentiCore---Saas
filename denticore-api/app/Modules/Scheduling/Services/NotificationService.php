<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Outbox\OutboxQueue;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenancy\TenantContext;

/**
 * Canal de notificaciones (SDD §1.9, §4.8; DD-10, DD-41, RF-155). Los Services de los demás
 * módulos lo invocan dentro de su transacción: la notificación `pendiente` y su mensaje de
 * outbox se confirman o se revierten junto con la operación de negocio (T-157). El envío lo
 * hace SendNotificationJob en la cola `notifications`.
 */
class NotificationService
{
    public const OUTBOX_TYPE = 'notification.send';

    public function __construct(private OutboxWriter $outbox) {}

    /**
     * Encola un correo para un usuario o un paciente. Devuelve null si el destinatario no
     * tiene correo registrado.
     *
     * @param  array<string, mixed>  $data  Datos de la plantilla, sin nombre ni correo del destinatario (RNF-110).
     * @param  array<string, string>  $links  Enlaces absolutos que muestra el correo.
     */
    public function sendEmail(
        NotificationEvent $event,
        User|Patient $recipient,
        array $data = [],
        array $links = [],
        ?string $dedupeKey = null,
    ): ?Notification {
        $email = $recipient->email;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $notification = new Notification;
        $notification->forceFill([
            'tenant_id' => TenantContext::id(),
            'channel' => 'correo',
            'event' => $event,
            'recipient_user_id' => $recipient instanceof User ? $recipient->id : null,
            'recipient_patient_id' => $recipient instanceof Patient ? $recipient->id : null,
            'recipient_email_hash' => self::emailHash($email),
            'payload' => [
                'template' => $event->value,
                'locale' => 'es-PE',
                'clinic' => ['name' => $this->clinicName()],
                'data' => $data,
                'links' => $links,
            ],
            'dedupe_key' => $dedupeKey,
        ])->save();

        $this->outbox->record(self::OUTBOX_TYPE, ['notification' => $notification->uuid], OutboxQueue::Notifications);

        return $notification;
    }

    /** RNF-110: la auditoría del envío identifica el correo sin guardarlo en claro. */
    public static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function clinicName(): ?string
    {
        $tenantId = TenantContext::id();

        return $tenantId === null ? null : Tenant::query()->whereKey($tenantId)->value('name');
    }
}
