<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Support\Database\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación por correo o en la aplicación (SDD §2.10 `notifications`, DD-10, RF-155).
 * `tenant_id` es nulo en los avisos de plataforma, por eso no usa BelongsToTenant: la crea
 * NotificationService con la clínica del contexto. El nombre y el correo del destinatario se
 * resuelven al enviar; nunca se guardan en `payload` (RNF-110).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property string $channel
 * @property NotificationEvent $event
 * @property int|null $recipient_user_id
 * @property int|null $recipient_patient_id
 * @property string|null $recipient_email_hash
 * @property array{template: string, locale: string, clinic: array{name: string|null}, data: array<string, mixed>, links: array<string, string>} $payload
 * @property string|null $dedupe_key
 * @property string $status
 * @property int $attempts
 * @property int $manual_resends
 * @property string|null $last_error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $read_at
 * @property-read User|null $recipientUser
 * @property-read Patient|null $recipientPatient
 */
class Notification extends Model
{
    use HasUuid;

    /** IE-04: 3 intentos; el tercer fallo deja la notificación `fallida`. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Defaults de columna espejados en PHP (ver Tenant::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pendiente',
        'attempts' => 0,
        'manual_resends' => 0,
    ];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'manual_resends' => 'integer',
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function recipientPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'recipient_patient_id');
    }
}
