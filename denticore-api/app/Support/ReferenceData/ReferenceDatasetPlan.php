<?php

namespace App\Support\ReferenceData;

use Ramsey\Uuid\Uuid;

final readonly class ReferenceDatasetPlan
{
    public const ATTENTIONS_PER_PATIENT = 8;

    public const ENTRIES_PER_PATIENT = 25;

    public const APPOINTMENTS_PER_PATIENT = 10;

    public const BUDGETS_PER_PATIENT = 2;

    public const LINES_PER_BUDGET = 4;

    /** @param list<int>|null $sizes */
    public function __construct(private int $seed, private ?array $sizes = null) {}

    /** @return list<int> */
    public function clinicSizes(): array
    {
        return $this->sizes ?? [...array_fill(0, 49, 2_000), 20_000];
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $sizes = $this->clinicSizes();
        $patients = array_sum($sizes);

        return [
            'tenants' => count($sizes),
            'patients' => $patients,
            'attentions' => $patients * self::ATTENTIONS_PER_PATIENT,
            'odontogram_entries' => $patients * self::ENTRIES_PER_PATIENT,
            'appointments' => $patients * self::APPOINTMENTS_PER_PATIENT,
            'budgets' => $patients * self::BUDGETS_PER_PATIENT,
            'budget_lines' => $patients * self::BUDGETS_PER_PATIENT * self::LINES_PER_BUDGET,
        ];
    }

    public function slug(int $clinic): string
    {
        return "cdr-{$this->seed}-{$clinic}";
    }

    public function clinicRucBody(int $clinic): string
    {
        return sprintf('20%06d%02d', $this->seed, $clinic);
    }

    public function uuid(int $clinic, string $record): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'denticore:'.$this->slug($clinic).':'.$record)->toString();
    }
}
