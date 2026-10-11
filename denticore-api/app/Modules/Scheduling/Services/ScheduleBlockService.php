<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueos de agenda (CUS-44; RF-143, RF-144, RN-47, RN-82): de un odontólogo o de toda la
 * clínica. Antes de crearlo se consulta su impacto; un bloqueo sobre citas activas exige una
 * decisión por cita (la aplicación de las decisiones llega con TASK-068).
 */
class ScheduleBlockService
{
    public function __construct(private AffectedAppointments $affected) {}

    /**
     * Citas activas que el bloqueo afectaría.
     *
     * @param  array{dentist: User|null, starts_at: string, ends_at: string}  $data
     * @return Collection<int, Appointment>
     */
    public function impact(array $data): Collection
    {
        return $this->affected->overlapping($data['dentist']?->id, Carbon::parse($data['starts_at'])->utc(), Carbon::parse($data['ends_at'])->utc());
    }

    /**
     * @param  array{dentist: User|null, starts_at: string, ends_at: string, kind: string, reason: string}  $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, User $actor): ScheduleBlock
    {
        return DB::transaction(function () use ($data, $actor): ScheduleBlock {
            $this->affected->ensureNone($this->impact($data));

            $block = (new ScheduleBlock)->forceFill([
                'tenant_id' => TenantContext::id(),
                'dentist_id' => $data['dentist']?->id,
                'period' => '['.Carbon::parse($data['starts_at'])->utc()->toIso8601String().','.Carbon::parse($data['ends_at'])->utc()->toIso8601String().')',
                'kind' => $data['kind'],
                'reason' => trim($data['reason']),
                'created_by' => $actor->id,
            ]);
            $block->save();

            return $block->load(['dentist', 'creator']);
        });
    }

    public function delete(ScheduleBlock $block): void
    {
        $block->delete();
    }
}
