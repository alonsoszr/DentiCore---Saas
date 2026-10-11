<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\WorkScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Franja del horario laboral de un odontólogo (SDD §2.10 `work_schedules`; CUS-44; RF-142,
 * DI-09): día ISO (1 = lunes), hora de inicio y fin y vigencia. `hours` y `validity` son columnas
 * generadas que sostienen el EXCLUDE de franjas solapadas.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $dentist_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property-read User $dentist
 */
#[UseFactory(WorkScheduleFactory::class)]
class WorkSchedule extends Model
{
    /** @use HasFactory<WorkScheduleFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

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
}
