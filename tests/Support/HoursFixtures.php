<?php

namespace Tests\Support;

use App\Enums\TimeCategory;
use App\Models\Employee;
use App\Models\TimesheetSegment;
use App\Support\BusinessTime;
use App\Support\TimeInterval;
use Carbon\CarbonImmutable;

/**
 * Fixtures en mémoire pour les tests de calculs horaires PC1-34.
 */
final class HoursFixtures
{
    public static function interval(string $start, string $end, ?int $segmentId = null): TimeInterval
    {
        return TimeInterval::between(
            CarbonImmutable::parse($start, BusinessTime::TIMEZONE)->utc(),
            CarbonImmutable::parse($end, BusinessTime::TIMEZONE)->utc(),
            $segmentId,
        );
    }

    public static function segment(
        string $start,
        string $end,
        TimeCategory $category = TimeCategory::TravailEffectif,
        ?int $id = null,
    ): TimesheetSegment {
        return TimesheetSegment::factory()->make([
            'id' => $id,
            'timesheet_id' => 1,
            'category' => $category,
            'start_at' => CarbonImmutable::parse($start, BusinessTime::TIMEZONE)->utc(),
            'end_at' => CarbonImmutable::parse($end, BusinessTime::TIMEZONE)->utc(),
        ]);
    }

    public static function employee(int $id = 1): Employee
    {
        return Employee::factory()->make(['id' => $id]);
    }
}
