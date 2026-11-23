<?php

use App\Support\ReferenceData\ReferenceDatasetPlan;

it('uses the reference volumes for staging and the documented large clinic', function () {
    $plan = new ReferenceDatasetPlan(42);

    expect($plan->clinicSizes())->toBe([...array_fill(0, 49, 2_000), 20_000])
        ->and($plan->totals())->toBe([
            'tenants' => 50,
            'patients' => 118_000,
            'attentions' => 944_000,
            'odontogram_entries' => 2_950_000,
            'appointments' => 1_180_000,
            'budgets' => 236_000,
            'budget_lines' => 944_000,
        ]);
})->group('TASK-072', 'RNF-037');

it('derives the same clinic identifiers from the same seed', function () {
    $first = new ReferenceDatasetPlan(42);
    $second = new ReferenceDatasetPlan(42);
    $different = new ReferenceDatasetPlan(43);

    expect($first->slug(1))->toBe($second->slug(1))
        ->and($first->slug(1))->not->toBe($first->slug(2))
        ->and($first->slug(1))->not->toBe($different->slug(1));
})->group('TASK-072', 'RNF-037', 'RES-08');
