<?php

use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\LocalDateTimeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

it('computes the Monday to Sunday week containing a date', function (): void {
    $week = Timesheet::weekFor('2026-11-04');

    expect($week['start']->toDateString())->toBe('2026-11-02')
        ->and($week['end']->toDateString())->toBe('2026-11-08')
        ->and($week['start']->isoWeekday())->toBe(1)
        ->and($week['end']->isoWeekday())->toBe(7);
});

it('computes weeks across a year boundary and a leap day', function (): void {
    $endOfYear = Timesheet::weekFor('2026-12-31');
    $leapDay = Timesheet::weekFor('2028-02-29');

    expect($endOfYear['start']->toDateString())->toBe('2026-12-28')
        ->and($endOfYear['end']->toDateString())->toBe('2027-01-03')
        ->and($leapDay['start']->toDateString())->toBe('2028-02-28')
        ->and($leapDay['end']->toDateString())->toBe('2028-03-05');
});

it('normalizes a Carbon input in another timezone to the business week', function (): void {
    $week = Timesheet::weekFor(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));

    expect($week['start']->toDateString())->toBe('2026-12-28')
        ->and($week['end']->toDateString())->toBe('2027-01-03');
});

it('refuses a period that is not a Monday to Sunday week', function (): void {
    expect(fn () => Timesheet::factory()->create([
        'period_start' => '2026-11-03',
        'period_end' => '2026-11-09',
    ]))->toThrow(LogicException::class);

    expect(fn () => Timesheet::factory()->create([
        'period_start' => '2026-11-02',
        'period_end' => '2026-11-07',
    ]))->toThrow(LogicException::class);

    expect(fn () => Timesheet::factory()->create([
        'period_start' => '2026-11-09',
        'period_end' => '2026-11-02',
    ]))->toThrow(LogicException::class);

    expect(fn () => Timesheet::factory()->create([
        'period_start' => '2026-11-02',
        'period_end' => '2026-11-10',
    ]))->toThrow(LogicException::class);

    expect(fn () => Timesheet::factory()->create([
        'period_start' => '2026-11-08',
        'period_end' => '2026-11-14',
    ]))->toThrow(LogicException::class);
});

it('accepts a week that crosses a year boundary', function (): void {
    $timesheet = Timesheet::factory()->forWeekOf('2026-12-31')->create();

    expect($timesheet->period_start?->toDateString())->toBe('2026-12-28')
        ->and($timesheet->period_end?->toDateString())->toBe('2027-01-03');
});

it('casts the status and derives local offsets from UTC instants', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-11-02')->create();

    $start = app(LocalDateTimeResolver::class)->resolve('2026-11-02', '08:00');
    $end = app(LocalDateTimeResolver::class)->resolve('2026-11-02', '12:00');

    $segment = TimesheetSegment::factory()->for($timesheet)->create([
        'category' => TimeCategory::Trajet,
        'start_at' => $start,
        'end_at' => $end,
        'start_offset_minutes' => 120,
        'end_offset_minutes' => 0,
    ]);

    $rawStart = DB::table('timesheet_segments')->where('id', $segment->id)->value('start_at');

    expect($timesheet->status)->toBe(TimesheetStatus::Brouillon)
        ->and($segment->category)->toBe(TimeCategory::Trajet)
        ->and($rawStart)->toBe('2026-11-02 07:00:00')
        ->and($segment->fresh()->start_at->equalTo($start))->toBeTrue()
        ->and($segment->fresh()->start_at->setTimezone(LocalDateTimeResolver::TIMEZONE)->format('Y-m-d H:i'))
        ->toBe('2026-11-02 08:00')
        ->and($segment->fresh()->start_at->getOffset())->toBe(0)
        ->and($segment->fresh()->start_offset_minutes)->toBe(60)
        ->and($segment->fresh()->end_offset_minutes)->toBe(60);

    // Un offset imposé directement est écrasé à l'enregistrement.
    $segment->start_offset_minutes = 120;
    $segment->save();

    expect($segment->fresh()->start_offset_minutes)->toBe(60);
});

it('derives both offsets for an instant inside the ambiguous autumn hour', function (): void {
    $employee = Employee::factory()->create();
    $timesheet = Timesheet::factory()->for($employee)->forWeekOf('2026-10-19')->create();
    $resolver = app(LocalDateTimeResolver::class);

    $summerStart = $resolver->resolve('2026-10-25', '02:30', 120);
    $summerEnd = $summerStart->addHour();

    $summerSegment = TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => $summerStart,
        'end_at' => $summerEnd,
    ]);

    expect($summerSegment->fresh()->start_offset_minutes)->toBe(120)
        ->and($summerSegment->fresh()->end_offset_minutes)->toBe(60)
        ->and($summerSegment->fresh()->start_at->setTimezone(LocalDateTimeResolver::TIMEZONE)->format('Y-m-d H:i'))
        ->toBe('2026-10-25 02:30');

    $winterStart = $resolver->resolve('2026-10-25', '02:30', 60);
    $winterEnd = $winterStart->addHour();

    $winterSegment = TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => $winterStart,
        'end_at' => $winterEnd,
    ]);

    expect($winterSegment->fresh()->start_offset_minutes)->toBe(60)
        ->and($winterSegment->fresh()->end_offset_minutes)->toBe(60);
});

it('rejects a date-time string without explicit temporal context', function (): void {
    $timesheet = Timesheet::factory()->create();

    expect(fn () => TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => '2026-11-02 08:00:00',
        'end_at' => '2026-11-02 09:00:00',
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a date-time string even when it carries an explicit offset', function (): void {
    $timesheet = Timesheet::factory()->create();

    expect(fn () => TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => '2026-11-02T08:00:00+01:00',
        'end_at' => '2026-11-02T09:00:00+01:00',
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a segment with seconds or a non-positive duration', function (): void {
    $timesheet = Timesheet::factory()->create();

    expect(fn () => TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => CarbonImmutable::parse('2026-11-02 08:00:30', LocalDateTimeResolver::TIMEZONE)->utc(),
        'end_at' => CarbonImmutable::parse('2026-11-02 09:00:00', LocalDateTimeResolver::TIMEZONE)->utc(),
    ]))->toThrow(LogicException::class);

    expect(fn () => TimesheetSegment::factory()->for($timesheet)->create([
        'start_at' => CarbonImmutable::parse('2026-11-02 09:00:00', LocalDateTimeResolver::TIMEZONE)->utc(),
        'end_at' => CarbonImmutable::parse('2026-11-02 08:00:00', LocalDateTimeResolver::TIMEZONE)->utc(),
    ]))->toThrow(LogicException::class);
});

it('refuses physical deletion of a timesheet', function (): void {
    $timesheet = Timesheet::factory()->create();

    expect(fn () => $timesheet->delete())->toThrow(LogicException::class)
        ->and(Timesheet::query()->whereKey($timesheet->id)->exists())->toBeTrue();
});
