<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cita (SDD §2.10 `appointments`; CUS-47 a CUS-51; estados de SRS §5.5.4). `period` es la columna
 * generada `[starts_at, ends_at)` sobre la que actúan los EXCLUDE de RN-46 y RN-74.
 * `risk_alert_id` se agrega en TASK-078 y `periodic_control_id` en TASK-151.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $dentist_id
 * @property int $appointment_type_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string $origin
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmation_channel
 * @property bool $reconfirmation_pending
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property string|null $cancel_detail
 * @property Carbon|null $no_show_marked_at
 * @property int $created_by
 * @property-read Patient $patient
 * @property-read User $dentist
 * @property-read AppointmentType $type
 * @property-read User $creator
 */
#[UseFactory(AppointmentFactory::class)]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    /** Estados que ocupan la franja (EXCLUDE de RN-46 y RN-74; SRS §5.5.4). */
    public const ACTIVE_STATUSES = ['programada', 'confirmada', 'en_atencion'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'reconfirmation_pending' => 'boolean',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_marked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class, 'appointment_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
