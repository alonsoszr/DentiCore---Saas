<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Resources\AppointmentResource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\CheckInService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;

/**
 * Check-in de la cita (SDD §4.3.8; CUS-50, CUS-25; RF-151, RF-067). La recepción y el
 * Administrador de Clínica registran la llegada; la respuesta incluye la atención abierta y, si
 * falta o está desactualizado el consentimiento, el aviso (RN-10, RN-15).
 */
class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}

    #[ProblemResponse(409, 'La cita no está programada ni confirmada, o el paciente ya tiene una atención abierta con el odontólogo (RF-082)')]
    #[ProblemResponse(422, 'Fuera de la ventana del día de la cita, desde 60 minutos antes hasta su fin (RN-50), o ficha bloqueada o fusionada')]
    public function store(Appointment $appointment): JsonResponse
    {
        ['appointment' => $checkedIn, 'attention' => $attention, 'consent_warning' => $warning] = $this->checkIns->checkIn($appointment);

        return AppointmentResource::make($checkedIn->load(['patient', 'dentist', 'type']))
            ->additional([
                'attention' => ['id' => $attention->uuid],
                'consent_warning' => $warning,
            ])
            ->response();
    }
}
