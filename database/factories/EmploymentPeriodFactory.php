<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentPeriod>
 */
class EmploymentPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'start_date' => now()->subYear()->startOfDay(),
            'end_date' => null,
        ];
    }

    public function ended(?string $endDate = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'end_date' => $endDate ?? now()->subDay()->toDateString(),
        ]);
    }
}
