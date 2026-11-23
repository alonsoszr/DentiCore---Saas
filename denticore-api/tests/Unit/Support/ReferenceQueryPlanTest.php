<?php

use App\Support\ReferenceData\ReferenceQueryPlan;

it('reports only sequential scans on relations with more than 10 000 rows', function () {
    $plan = ['Node Type' => 'Append', 'Plans' => [
        ['Node Type' => 'Seq Scan', 'Schema' => 'public', 'Relation Name' => 'patients'],
        ['Node Type' => 'Seq Scan', 'Schema' => 'public', 'Relation Name' => 'subscription_plans'],
        ['Node Type' => 'Bitmap Heap Scan', 'Schema' => 'public', 'Relation Name' => 'appointments'],
        ['Node Type' => 'Gather', 'Plans' => [
            ['Node Type' => 'Seq Scan', 'Schema' => 'public', 'Relation Name' => 'audit_logs_y2026', 'Parallel Aware' => true],
        ]],
        ['Node Type' => 'Seq Scan', 'Schema' => 'public', 'Relation Name' => 'patients'],
    ]];
    $counts = [
        'public.patients' => 10_001,
        'public.subscription_plans' => 10_000,
        'public.audit_logs_y2026' => 20_000,
    ];

    expect(ReferenceQueryPlan::violations($plan, fn (string $relation): int => $counts[$relation]))
        ->toBe(['public.patients', 'public.audit_logs_y2026']);
})->group('TASK-072', 'RNF-030');
