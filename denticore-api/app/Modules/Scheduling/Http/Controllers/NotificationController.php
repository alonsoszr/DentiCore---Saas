<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Http\Resources\NotificationResource;
use App\Modules\Scheduling\Services\NotificationInbox;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Bandeja in-app del usuario (SDD §4.3.8, grupo AUTH; CUS-53; RF-158): los avisos de los últimos
 * 90 días, con el contador de no leídos. Un aviso ajeno responde 404.
 */
class NotificationController extends Controller
{
    /** Avisos que devuelve la bandeja, del más reciente al más antiguo. */
    private const LIMIT = 50;

    public function __construct(private NotificationInbox $inbox) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return NotificationResource::collection($this->inbox->latest($user, self::LIMIT))
            ->additional(['meta' => ['unread_count' => $this->inbox->unreadCount($user)]]);
    }

    public function markRead(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        return NotificationResource::make($this->inbox->markRead($user, $notification));
    }

    public function markAllRead(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->inbox->markAllRead($user);

        return response()->noContent();
    }
}
