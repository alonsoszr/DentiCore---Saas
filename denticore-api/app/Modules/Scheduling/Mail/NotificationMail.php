<?php

namespace App\Modules\Scheduling\Mail;

use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Models\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de una notificación (SDD §4.8): HTML y texto plano (RNF-049) con el nombre de la
 * clínica (RF-155). La plantilla de cada evento está en `resources/views/mail/notifications/`.
 */
class NotificationMail extends Mailable
{
    public function __construct(public Notification $notification, public string $recipientName) {}

    public function envelope(): Envelope
    {
        $clinic = $this->notification->payload['clinic']['name'] ?? null;

        return new Envelope(subject: $clinic === null ? $this->subjectLine() : "{$this->subjectLine()} · {$clinic}");
    }

    public function content(): Content
    {
        $template = $this->notification->event->value;

        return new Content(
            view: "mail.notifications.{$template}",
            text: "mail.notifications.{$template}-text",
            with: [
                'recipientName' => $this->recipientName,
                'clinicName' => $this->notification->payload['clinic']['name'] ?? null,
                'data' => $this->notification->payload['data'],
                'links' => $this->notification->payload['links'],
            ],
        );
    }

    private function subjectLine(): string
    {
        return match ($this->notification->event) {
            NotificationEvent::InvitacionActivacion => 'Activa tu cuenta de DentiCore',
            NotificationEvent::RestablecimientoContrasena => 'Restablece tu contraseña',
            NotificationEvent::CuentaBloqueada => 'Tu cuenta se bloqueó temporalmente',
            NotificationEvent::ClinicaSuspendida => 'La clínica fue suspendida',
            NotificationEvent::ClinicaReactivada => 'La clínica fue reactivada',
            NotificationEvent::ConsentimientoConstancia => 'Constancia de tu consentimiento',
            default => 'Aviso de DentiCore',
        };
    }
}
