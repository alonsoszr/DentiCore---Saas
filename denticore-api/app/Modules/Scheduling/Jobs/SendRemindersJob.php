<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recordatorios de las citas activas de una clínica que empiezan dentro de 23 h 30 min a 24 h
 * (SDD §1.9, §4.8; CUS-52, RF-156, RN-52). Corre cada 5 minutos: la ventana de 30 minutos cubre
 * cualquier demora y la clave `recordatorio:{cita}` evita un segundo envío.
 */
class SendRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const WINDOW_START_MINUTES = 23 * 60 + 30;

    public const WINDOW_END_MINUTES = 24 * 60;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(AppointmentService $appointments): void
    {
        Appointment::query()
            ->whereIn('status', ['programada', 'confirmada'])
            ->where('starts_at', '>=', now()->addMinutes(self::WINDOW_START_MINUTES))
            ->where('starts_at', '<=', now()->addMinutes(self::WINDOW_END_MINUTES))
            ->orderBy('starts_at')
            ->get()
            ->each(fn (Appointment $appointment) => $appointments->remind($appointment));
    }
}
