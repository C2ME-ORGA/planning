<?php

namespace Tests\Support;

use App\Models\Employee;
use App\Services\Hours\MonthlyReferenceChange;
use App\Services\Hours\MonthlyReferenceProvider;

/**
 * Provider en mémoire pour tester la résolution des références PC1-34.
 */
final class ArrayMonthlyReferenceProvider implements MonthlyReferenceProvider
{
    /**
     * @param  array<int, array<int, MonthlyReferenceChange>>  $changesByEmployee
     */
    public function __construct(
        private readonly array $changesByEmployee,
    ) {}

    /**
     * @return iterable<MonthlyReferenceChange>
     */
    public function changesFor(Employee $employee): iterable
    {
        return $this->changesByEmployee[(int) $employee->getKey()] ?? [];
    }
}
