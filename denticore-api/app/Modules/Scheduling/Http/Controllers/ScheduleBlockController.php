<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Http\Requests\ScheduleBlockRequest;
use App\Modules\Scheduling\Http\Resources\AffectedAppointmentResource;
use App\Modules\Scheduling\Http\Resources\ScheduleBlockResource;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Services\Dentists;
use App\Modules\Scheduling\Services\ScheduleBlockService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueos de agenda (SDD §4.3.8; CUS-44; RF-143, RF-144, RN-82). El odontólogo solo bloquea su
 * propia agenda; el Administrador de Clínica, la de cualquier odontólogo o toda la clínica.
 */
class ScheduleBlockController extends Controller
{
    public function __construct(private ScheduleBlockService $blocks, private Dentists $dentists) {}

    /**
     * Bloqueos que terminan desde `from` (hoy por defecto); `dentist_id` deja los de ese
     * odontólogo junto con los de toda la clínica.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['dentist_id' => ['sometimes', 'uuid'], 'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date']]);
        $dentist = $request->filled('dentist_id') ? $this->dentists->find($request->string('dentist_id')->toString()) : null;
        $from = $request->date('from') ?? now()->startOfDay();
        $to = $request->date('to');

        $blocks = ScheduleBlock::query()
            ->with(['dentist', 'creator'])
            ->whereRaw('upper(period) > ?', [$from->format(DATE_ATOM)])
            ->when($to !== null, fn ($query) => $query->whereRaw('lower(period) < ?', [$to?->format(DATE_ATOM)]))
            ->when($dentist !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('dentist_id')->orWhere('dentist_id', $dentist?->id)))
            ->orderByRaw('lower(period)')
            ->get();

        return ScheduleBlockResource::collection($blocks);
    }

    /**
     * RF-144: citas activas que el bloqueo afectaría, antes de crearlo.
     */
    public function impact(ScheduleBlockRequest $request): AnonymousResourceCollection
    {
        $data = $this->data($request);
        Gate::authorize('create', [ScheduleBlock::class, $data['dentist']]);

        return AffectedAppointmentResource::collection($this->blocks->impact($data));
    }

    #[ProblemResponse(422, 'Citas activas afectadas sin una decisión por cita (RN-82)')]
    public function store(ScheduleBlockRequest $request): JsonResponse
    {
        $data = $this->data($request);
        Gate::authorize('create', [ScheduleBlock::class, $data['dentist']]);

        /** @var User $actor */
        $actor = $request->user();
        $block = $this->blocks->create([
            ...$data,
            'kind' => $request->string('kind')->toString(),
            'reason' => $request->string('reason')->toString(),
        ], $actor);

        return ScheduleBlockResource::make($block)->response()->setStatusCode(201);
    }

    public function destroy(ScheduleBlock $block): Response
    {
        Gate::authorize('delete', $block);

        $this->blocks->delete($block);

        return response()->noContent();
    }

    /**
     * @return array{dentist: User|null, starts_at: string, ends_at: string}
     *
     * @throws ValidationException
     */
    private function data(ScheduleBlockRequest $request): array
    {
        $uuid = $request->input('dentist_id');
        $dentist = $this->dentists->findOrNull(is_string($uuid) ? $uuid : null);

        if (is_string($uuid) && $dentist === null) {
            throw ValidationException::withMessages(['dentist_id' => 'El odontólogo indicado no existe en la clínica.']);
        }

        return [
            'dentist' => $dentist,
            'starts_at' => $request->string('starts_at')->toString(),
            'ends_at' => $request->string('ends_at')->toString(),
        ];
    }
}
