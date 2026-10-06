<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Mail\NotificationMail;
use App\Modules\Scheduling\Models\Notification;
use App\Support\Outbox\OutboxJob;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Envía una notificación por correo en la cola `notifications` (SDD §1.9, §4.8; DD-10, IE-04,
 * RF-155). 3 intentos con espera exponencial; cada fallo queda registrado en la notificación
 * fuera de la transacción revertida y al tercero pasa a `fallida`. La operación de negocio que
 * la originó ya está confirmada y no se revierte.
 */
class SendNotificationJob extends OutboxJob
{
    public int $tries = Notification::MAX_ATTEMPTS;

    /**
     * IE-04: espera de 1, 4 y 16 minutos entre intentos.
     *
     * @var list<int>
     */
    public array $backoff = [60, 240, 960];

    public function handle(): void
    {
        try {
            parent::handle();
        } catch (Throwable $exception) {
            $this->recordFailedAttempt($exception);

            throw $exception;
        }
    }

    protected function process(OutboxMessage $message): void
    {
        $notification = $this->notification($message);

        if ($notification === null || $notification->status !== 'pendiente') {
            return;
        }

        $recipient = $this->recipient($notification);

        Mail::to((string) $recipient->email)->send(new NotificationMail($notification, $this->recipientName($recipient)));

        $notification->forceFill([
            'status' => 'enviada',
            'attempts' => $notification->attempts + 1,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function recordFailedAttempt(Throwable $exception): void
    {
        $message = OutboxMessage::query()->where('uuid', $this->messageUuid)->first();
        $notification = $message === null ? null : $this->notification($message);

        if ($notification === null || $notification->status !== 'pendiente') {
            return;
        }

        $attempts = $notification->attempts + 1;

        $notification->forceFill([
            'attempts' => $attempts,
            'status' => $attempts >= Notification::MAX_ATTEMPTS ? 'fallida' : 'pendiente',
            'last_error' => Str::limit($exception->getMessage(), 297),
        ])->save();
    }

    private function notification(OutboxMessage $message): ?Notification
    {
        return Notification::query()->where('uuid', $message->payload['notification'] ?? null)->first();
    }

    private function recipient(Notification $notification): User|Patient
    {
        $recipient = $notification->recipientUser ?? $notification->recipientPatient;

        if ($recipient === null || ! is_string($recipient->email) || $recipient->email === '') {
            throw new RuntimeException("La notificación {$notification->uuid} no tiene un destinatario con correo.");
        }

        return $recipient;
    }

    private function recipientName(User|Patient $recipient): string
    {
        return $recipient instanceof User ? $recipient->name : trim("{$recipient->first_name} {$recipient->last_name}");
    }
}
