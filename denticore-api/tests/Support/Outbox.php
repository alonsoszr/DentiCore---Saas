<?php

namespace Tests\Support;

use App\Support\Outbox\OutboxMessage;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * Aserciones sobre el outbox (SDD §6.2: `Outbox::assertRecorded(type)`).
 */
final class Outbox
{
    /**
     * @param  (Closure(OutboxMessage): bool)|null  $filter
     */
    public static function assertRecorded(string $type, ?Closure $filter = null, ?int $times = null): void
    {
        $count = self::matching($type, $filter);

        $times === null
            ? Assert::assertGreaterThan(0, $count, "No se registró ningún mensaje de outbox «{$type}».")
            : Assert::assertSame($times, $count, "Se esperaban {$times} mensajes de outbox «{$type}» y hay {$count}.");
    }

    public static function assertNotRecorded(string $type): void
    {
        Assert::assertSame(0, self::matching($type, null), "Se registró un mensaje de outbox «{$type}» inesperado.");
    }

    /**
     * @param  (Closure(OutboxMessage): bool)|null  $filter
     */
    private static function matching(string $type, ?Closure $filter): int
    {
        return OutboxMessage::query()
            ->where('type', $type)
            ->get()
            ->filter($filter ?? fn (): bool => true)
            ->count();
    }
}
