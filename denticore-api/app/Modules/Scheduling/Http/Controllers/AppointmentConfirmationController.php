<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use App\Support\Tokens\OneTimeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirmación de la cita (SDD §4.3.8; CUS-49; RF-150): por el personal, cuando el paciente
 * confirma por teléfono, o por el paciente con el enlace de un solo uso del correo, sin sesión.
 */
class AppointmentConfirmationController extends Controller
{
    public function __construct(private AppointmentService $appointments) {}

    #[ProblemResponse(409, 'La cita no está programada ni confirmada')]
    public function store(Appointment $appointment): AppointmentResource
    {
        return AppointmentResource::make($this->appointments->confirm($appointment, 'telefono')->load(['patient', 'dentist', 'type']));
    }

    /**
     * El token resuelve la clínica (`tenant.token`); vencido, usado o inexistente → 404 (RNF-112).
     * Devuelve solo lo necesario para mostrar la cita confirmada, sin datos del paciente.
     */
    #[ProblemResponse(409, 'La cita ya no está programada ni confirmada')]
    public function confirmByLink(Request $request): JsonResponse
    {
        /** @var OneTimeToken $token */
        $token = $request->attributes->get('one_time_token');
        $appointment = $this->appointments->confirmByToken($token)->load(['dentist', 'type']);
        $clinic = TenantContext::tenantOrFail();
        $timezone = ClinicClock::for($clinic)->timezone();

        return response()->json(['data' => [
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => $timezone,
            'dentist' => $appointment->dentist->name,
            'type' => $appointment->type->name,
            'clinic' => $clinic->name,
        ]]);
    }
}
