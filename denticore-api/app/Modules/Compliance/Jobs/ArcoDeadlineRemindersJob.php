<?php

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\BusinessDays;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Aviso del plazo ARCO de una clínica (SDD §1.9, §4.8; CUS-64 FA-2; RF-185, CA-64.5, DD-26): a las
 * solicitudes sin resolver a las que les faltan 2 días hábiles o menos y que aún no tienen aviso,
 * `arco_plazo_proximo` in_app y por correo al Oficial de Datos Personales y a los administradores
 * activos. `reminder_sent_at` evita repetirlo; si un día no corrió, el aviso sale al siguiente.
 */
class ArcoDeadlineRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const NOTICE_BUSINESS_DAYS = 2;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(NotificationService $notifications): void
    {
        $today = CarbonImmutable::parse(ClinicClock::for(TenantContext::tenantOrFail())->localDate(now()));
        $recipients = User::query()
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('role', 'clinic_admin')
            ->where('status', 'activo')
            ->orderBy('id')
            ->get();
        $link = rtrim((string) config('app.spa_url'), '/').'/c/'.TenantContext::tenantOrFail()->slug.'/app/cumplimiento/arco';

        ArcoRequest::query()
            ->whereIn('status', ArcoRequest::OPEN_STATUSES)
            ->whereNull('reminder_sent_at')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (ArcoRequest $request) => BusinessDays::remaining($today, $request->due_date) <= self::NOTICE_BUSINESS_DAYS)
            ->each(fn (ArcoRequest $request) => DB::transaction(function () use ($request, $recipients, $notifications, $link): void {
                $data = ['type' => $request->type, 'due_date' => $request->due_date->toDateString(), 'request' => $request->uuid];
                $links = ['request' => "{$link}/{$request->uuid}"];

                foreach ($recipients as $recipient) {
                    $notifications->notifyInApp(NotificationEvent::ArcoPlazoProximo, $recipient, $data, $links, "arco_plazo_proximo:{$request->uuid}:{$recipient->id}");
                    $notifications->sendEmail(NotificationEvent::ArcoPlazoProximo, $recipient, $data, $links, "arco_plazo_proximo:correo:{$request->uuid}:{$recipient->id}");
                }

                $request->forceFill(['reminder_sent_at' => now()])->save();
            }));
    }
}
