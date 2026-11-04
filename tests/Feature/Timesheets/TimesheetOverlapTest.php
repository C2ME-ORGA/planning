<?php

use App\Enums\TimeCategory;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\LocalDateTimeResolver;
use App\Services\Timesheets\TimesheetOverlapDetector;
use Carbon\CarbonImmutable;

function overlapInstant(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, LocalDateTimeResolver::TIMEZONE);
}

it('detects an overlap between two distinct timesheets of the same employee', function (): void {
    $employee = Employee::factory()->create();

    $firstWeek = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();
    $secondWeek = Timesheet::factory()->for($employee)->forWeekOf('2026-11-09')->create();

    $crossing = TimesheetSegment::factory()->for($firstWeek)
        ->between('2026-11-08 22:00:00', '2026-11-09 02:00:00')
        ->create();

    $nextWeek = TimesheetSegment::factory()->for($secondWeek)
        ->between('2026-11-09 01:00:00', '2026-11-09 03:00:00')
        ->create();

    $overlaps = app(TimesheetOverlapDetector::class)->overlapsForSegment($nextWeek);

    expect($overlaps->pluck('id')->all())->toBe([$crossing->id])
        ->and($overlaps->first()->timesheet_id)->toBe($firstWeek->id)
        ->and(TimesheetSegment::query()->count())->toBe(2);
});

it('detects overlaps in the same timesheet across categories', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $work = TimesheetSegment::factory()->for($timesheet)
        ->category(TimeCategory::TravailEffectif)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $pause = TimesheetSegment::factory()->for($timesheet)
        ->category(TimeCategory::Pause)
        ->between('2026-11-02 11:00:00', '2026-11-02 13:00:00')
        ->create();

    $detector = app(TimesheetOverlapDetector::class);

    expect($detector->overlapsForSegment($pause)->pluck('id')->all())->toBe([$work->id])
        ->and($detector->overlapsForSegment($work)->pluck('id')->all())->toBe([$pause->id]);
});

it('does not report adjacent segments, another employee or a segment itself', function (): void {
    $employee = Employee::factory()->create();
    $otherEmployee = Employee::factory()->create();

    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();
    $otherTimesheet = Timesheet::factory()->for($otherEmployee)->forWeekOf('2026-11-02')->create();

    $reference = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $adjacent = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 12:00:00', '2026-11-02 13:00:00')
        ->create();

    $otherSegment = TimesheetSegment::factory()->for($otherTimesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $detector = app(TimesheetOverlapDetector::class);

    expect($detector->overlapsForSegment($reference)->pluck('id')->all())->toBe([])
        ->and($detector->overlapsForSegment($adjacent)->pluck('id')->all())->toBe([])
        ->and($detector->overlapsForSegment($otherSegment)->pluck('id')->all())->toBe([]);
});

it('detects a candidate range without any exclusion parameter', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $existing = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $detector = app(TimesheetOverlapDetector::class);

    expect($detector->overlapsForCandidate(
        $employee,
        overlapInstant('2026-11-02 11:00:00'),
        overlapInstant('2026-11-02 13:00:00'),
    )->pluck('id')->all())->toBe([$existing->id]);
});

it('never reports the segment itself when its interval is unchanged', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $segment = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $segment->save();

    expect(app(TimesheetOverlapDetector::class)->overlapsForSegment($segment->fresh())->pluck('id')->all())
        ->toBe([]);
});

it('detects conflicts after moving a segment and excludes the moved segment', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $moved = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 10:00:00')
        ->create();

    $other = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 11:00:00', '2026-11-02 13:00:00')
        ->create();

    $moved->update([
        'start_at' => overlapInstant('2026-11-02 11:30:00'),
        'end_at' => overlapInstant('2026-11-02 12:30:00'),
    ]);

    expect(app(TimesheetOverlapDetector::class)->overlapsForSegment($moved->fresh())->pluck('id')->all())
        ->toBe([$other->id])
        ->and($moved->fresh()->start_offset_minutes)->toBe(60)
        ->and($moved->fresh()->end_offset_minutes)->toBe(60);

    $moved->update([
        'start_at' => overlapInstant('2026-11-02 14:00:00'),
        'end_at' => overlapInstant('2026-11-02 15:00:00'),
    ]);

    expect(app(TimesheetOverlapDetector::class)->overlapsForSegment($moved->fresh())->pluck('id')->all())
        ->toBe([]);
});

it('exposes non-blocking overlap warnings without nominative data', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $first = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 08:00:00', '2026-11-02 12:00:00')
        ->create();

    $second = TimesheetSegment::factory()->for($timesheet)
        ->between('2026-11-02 11:00:00', '2026-11-02 13:00:00')
        ->create();

    $warnings = app(TimesheetOverlapDetector::class)->warningsForSegment($second);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toMatchArray([
            'type' => 'segment_overlap',
            'segment_id' => $first->id,
            'timesheet_id' => $timesheet->id,
        ])
        ->and($warnings[0])->not->toHaveKey('employee_name')
        ->and($warnings[0])->not->toHaveKey('employee_id');
});
