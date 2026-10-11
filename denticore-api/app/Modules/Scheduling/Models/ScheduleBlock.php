<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ScheduleBlockFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo de agenda (SDD §2.10 `schedule_blocks`; CUS-44; RF-143, RN-47): de un odontólogo o de
 * toda la clínica (`dentist_id` NULL), sobre `period` (tstzrange `[)`) y con motivo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $dentist_id
 * @property string $period
 * @property string $kind
 * @property string $reason
 * @property int $created_by
 * @property-read User|null $dentist
 * @property-read User $creator
 */
#[UseFactory(ScheduleBlockFactory::class)]
class ScheduleBlock extends Model
{
    /** @use HasFactory<ScheduleBlockFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    public const KINDS = ['vacaciones', 'capacitacion', 'feriado', 'cierre', 'otro'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
