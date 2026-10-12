<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Console\PruneInAppNotificationsCommand;
use App\Modules\Scheduling\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Bandeja in-app de un usuario (CUS-53; RF-158): sus avisos de los últimos 90 días. Cada usuario
 * ve solo los suyos: `notifications` no tiene RLS, así que el filtro por destinatario es explícito.
 */
class NotificationInbox
{
    /**
     * @return Collection<int, Notification>
     */
    public function latest(User $user, int $limit): Collection
    {
        return $this->query($user)->latest('created_at')->latest('id')->limit($limit)->get();
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /**
     * Un aviso ajeno, de otro canal o ya eliminado responde 404.
     *
     * @throws ModelNotFoundException<Notification>
     */
    public function markRead(User $user, string $uuid): Notification
    {
        $notification = $this->query($user)->where('uuid', $uuid)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllRead(User $user): void
    {
        $this->query($user)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * @return Builder<Notification>
     */
    private function query(User $user): Builder
    {
        return Notification::query()
            ->where('channel', 'in_app')
            ->where('recipient_user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(PruneInAppNotificationsCommand::RETENTION_DAYS));
    }
}
