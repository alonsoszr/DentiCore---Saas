<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Http\Requests\WorkScheduleRequest;
use App\Modules\Scheduling\Http\Resources\WorkScheduleResource;
use App\Modules\Scheduling\Services\WorkScheduleService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Horario laboral de un odontólogo (SDD §4.3.8; CUS-44; RF-142, RF-144). El personal lo consulta;
 * solo el Administrador de Clínica lo reemplaza. Un uuid que no es odontólogo de la clínica → 404.
 */
class WorkScheduleController extends Controller
{
    public function __construct(private WorkScheduleService $schedules) {}

    /**
     * Franjas vigentes hoy o más adelante.
     */
    public function index(User $dentist): AnonymousResourceCollection
    {
        return WorkScheduleResource::collection($this->schedules->current($dentist));
    }

    /**
     * RF-142: reemplaza el horario desde `valid_from`. `decisions` reprograma o cancela cada cita
     * activa que el horario nuevo deja fuera (RF-144, RN-82).
     */
    #[ProblemResponse(422, 'Franjas solapadas del mismo día (RF-142) o citas activas afectadas sin una decisión por cita (RN-82)')]
    public function replace(WorkScheduleRequest $request, User $dentist): AnonymousResourceCollection
    {
        /** @var array{valid_from: string, slots: list<array{weekday: int, start_time: string, end_time: string}>, decisions?: list<array{appointment_id: string, action: string, new_starts_at?: string|null}>} $data */
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        return WorkScheduleResource::collection(
            $this->schedules->replace($dentist, $data, $actor, $data['decisions'] ?? []),
        );
    }
}
