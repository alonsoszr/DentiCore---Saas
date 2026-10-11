<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Sala de espera (SDD §4.3.8; CUS-77; RF-153): las citas del día local con llegada registrada
 * (`en_atencion`), en orden de llegada. El tiempo de espera se calcula desde `checked_in_at`; el
 * SPA la actualiza cada 30 segundos.
 */
class WaitingRoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['dentist_id' => ['sometimes', 'uuid']]);
        $clock = ClinicClock::for(TenantContext::tenantOrFail());
        $today = $clock->localDate($clock->now());

        return AppointmentResource::collection(
            Appointment::query()
                ->with(['patient', 'dentist', 'type'])
                ->where('status', 'en_atencion')
                ->where('starts_at', '>=', $clock->startOfLocalDay($today))
                ->where('starts_at', '<', $clock->startOfLocalDay($today)->addDay())
                ->when($request->filled('dentist_id'), fn ($query) => $query->whereHas('dentist', fn ($dentist) => $dentist->where('uuid', $request->string('dentist_id')->toString())))
                ->orderBy('checked_in_at')
                ->get(),
        );
    }
}
