<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Marca como `inasistencia` las citas `programada` o `confirmada` de una clínica cuyo fin pasó hace
 * 30 minutos o más (SDD §5.7; CUS-51, RN-51). Corre en el contexto de la clínica (TenantAwareJob);
 * cada cita se marca bloqueada en su propia transacción.
 */
class MarkNoShowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const GRACE_MINUTES = 30;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(AuditLogger $audit): void
    {
        Appointment::query()
            ->whereIn('status', ['programada', 'confirmada'])
            ->where('ends_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => DB::transaction(function () use ($id, $audit): void {
                $appointment = Appointment::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if (! in_array($appointment->status, ['programada', 'confirmada'], true)) {
                    return;
                }

                $appointment->forceFill([
                    'status' => 'inasistencia',
                    'no_show_marked_at' => now(),
                    'reconfirmation_pending' => false,
                ])->save();
                $audit->record(AuditEvent::AppointmentNoShow, $appointment, ['status']);
            }));
    }
}
