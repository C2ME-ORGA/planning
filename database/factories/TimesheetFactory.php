<?php

namespace Database\Factories;

use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Timesheet>
 */
class TimesheetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $week = Timesheet::weekFor(CarbonImmutable::now());

        return [
            'employee_id' => Employee::factory(),
            'status' => TimesheetStatus::Brouillon,
            'period_start' => $week['start']->toDateString(),
            'period_end' => $week['end']->toDateString(),
        ];
    }

    public function forWeekOf(string $date): static
    {
        $week = Timesheet::weekFor($date);

        return $this->state(fn (array $attributes): array => [
            'period_start' => $week['start']->toDateString(),
            'period_end' => $week['end']->toDateString(),
        ]);
    }

    public function status(TimesheetStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
