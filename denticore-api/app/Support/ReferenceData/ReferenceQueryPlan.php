<?php

namespace App\Support\ReferenceData;

use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Evidencia de RNF-030 mediante el plan real de PostgreSQL. El límite de 10 001
 * permite confirmar el incumplimiento sin contar toda la tabla.
 */
final class ReferenceQueryPlan
{
    private const MAX_SEQUENTIAL_ROWS = 10_000;

    /** @return array{indexes: list<string>, violations: list<string>} */
    public static function inspect(Builder $query, ?ConnectionInterface $connection = null): array
    {
        $connection ??= $query->getConnection();
        $result = $connection->selectOne('EXPLAIN (FORMAT JSON, VERBOSE) '.$query->toSql(), $query->getBindings());
        $plan = json_decode((string) ($result->{'QUERY PLAN'} ?? ''), true, flags: JSON_THROW_ON_ERROR)[0]['Plan'] ?? null;
        if (! is_array($plan)) {
            throw new RuntimeException('PostgreSQL no devolvió un plan de consulta.');
        }

        $indexes = [];
        foreach (self::nodes($plan) as $node) {
            if (is_string($node['Index Name'] ?? null)) {
                $indexes[] = $node['Index Name'];
            }
        }

        return [
            'indexes' => $indexes,
            'violations' => self::violations($plan, function (string $relation) use ($connection): int {
                $sample = $connection->table($relation)->selectRaw('1')->limit(self::MAX_SEQUENTIAL_ROWS + 1);

                return $connection->table($relation)->fromSub($sample, 'cdr_row_limit')->count();
            }),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  callable(string):int  $rowCount
     * @return list<string>
     */
    public static function violations(array $plan, callable $rowCount): array
    {
        $relations = [];
        foreach (self::nodes($plan) as $node) {
            $relation = $node['Relation Name'] ?? null;
            $schema = $node['Schema'] ?? null;
            if (($node['Node Type'] ?? null) === 'Seq Scan' && is_string($relation) && is_string($schema)) {
                $relations["{$schema}.{$relation}"] = true;
            }
        }

        return array_values(array_filter(
            array_keys($relations),
            fn (string $relation): bool => $rowCount($relation) > self::MAX_SEQUENTIAL_ROWS,
        ));
    }

    /**
     * @param  array<string, mixed>  $root
     * @return Generator<int, array<string, mixed>>
     */
    private static function nodes(array $root): Generator
    {
        yield $root;
        foreach ($root['Plans'] ?? [] as $child) {
            yield from self::nodes($child);
        }
    }
}
