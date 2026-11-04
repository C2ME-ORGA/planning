<?php

namespace Database\Factories;

use App\Enums\TimeCategory;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\LocalDateTimeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetSegment>
 */
class TimesheetSegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::parse('2026-11-02 08:00:00', LocalDateTimeResolver::TIMEZONE);
        $end = $start->addHours(4);

        return [
            'timesheet_id' => Timesheet::factory(),
            'category' => TimeCategory::TravailEffectif,
            'start_at' => $start->utc(),
            'end_at' => $end->utc(),
        ];
    }

    public function category(TimeCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }

    public function between(string $start, string $end): static
    {
        $startAt = CarbonImmutable::parse($start, LocalDateTimeResolver::TIMEZONE);
        $endAt = CarbonImmutable::parse($end, LocalDateTimeResolver::TIMEZONE);

        return $this->state(fn (array $attributes): array => [
            'start_at' => $startAt->utc(),
            'end_at' => $endAt->utc(),
        ]);
    }
}
