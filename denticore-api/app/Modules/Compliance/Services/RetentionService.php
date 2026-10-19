<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Scheduling\ScheduledTaskLedger;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/** Política de archivo, sin borrado físico (SDD §5.12; CUS-69, RF-190, RN-68). */
class RetentionService
{
    private const CHUNK_SIZE = 500;

    public function __construct(private AuditLogger $audit, private ScheduledTaskLedger $ledger) {}

    public function apply(Tenant $tenant): void
    {
        $this->ledger->run('retention:apply', $tenant->id, function (?CarbonImmutable $since, CarbonImmutable $until) use ($tenant): void {
            $localNow = $until->setTimezone(ClinicClock::for($tenant)->timezone());

            $this->transition($tenant->id, ['activo'], $localNow->subYearsNoOverflow(5)->utc(), 'pasivo');
            $this->transition($tenant->id, ['pasivo', 'bloqueado'], $localNow->subYearsNoOverflow(20)->utc(), 'apto_eliminacion');
        });
    }

    /**
     * La marca avanza con los estados y su auditoría en la transacción del ledger. Se revisa
     * todo lo pendiente, no solo la ventana, para incluir fichas antiguas importadas después.
     * No se cargan columnas cifradas ni registros clínicos, y el lote acota la memoria.
     *
     * @param  list<string>  $statuses
     */
    private function transition(int $tenantId, array $statuses, CarbonImmutable $cutoff, string $status): void
    {
        Patient::query()
            ->select(['id', 'uuid', 'tenant_id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('archive_status', $statuses)
            ->whereRaw('COALESCE(last_attention_at, created_at) < ?', [$cutoff])
            ->lockForUpdate()
            ->chunkById(self::CHUNK_SIZE, function (Collection $patients) use ($status): void {
                Patient::query()->whereKey($patients->modelKeys())->update(['archive_status' => $status]);

                foreach ($patients as $patient) {
                    $this->audit->record(AuditEvent::PatientArchived, $patient, changedFields: ['archive_status'], meta: ['status' => $status]);
                }
            });
    }
}
