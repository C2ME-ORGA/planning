<?php

use App\Models\Timesheet;
use App\Support\TimesheetWeekBounds;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('computes the march daylight-saving week bounds in UTC', function (): void {
    $timesheet = new Timesheet([
        'period_start' => '2026-03-23',
        'period_end' => '2026-03-29',
    ]);

    $bounds = TimesheetWeekBounds::for($timesheet);

    expect($bounds['lower']->toIso8601String())->toBe('2026-03-22T23:00:00+00:00')
        ->and($bounds['upper']->toIso8601String())->toBe('2026-03-29T21:59:00+00:00');
});

it('accepts a segment within the march daylight-saving week', function (): void {
    $timesheet = new Timesheet([
        'period_start' => '2026-03-23',
        'period_end' => '2026-03-29',
    ]);

    expect(fn () => TimesheetWeekBounds::assertContains(
        $timesheet,
        CarbonImmutable::parse('2026-03-29 22:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-03-29 23:00', 'Europe/Paris'),
    ))->not->toThrow(ValidationException::class);
});

it('computes the october daylight-saving week bounds in UTC', function (): void {
    $timesheet = new Timesheet([
        'period_start' => '2026-10-19',
        'period_end' => '2026-10-25',
    ]);

    $bounds = TimesheetWeekBounds::for($timesheet);

    expect($bounds['lower']->toIso8601String())->toBe('2026-10-18T22:00:00+00:00')
        ->and($bounds['upper']->toIso8601String())->toBe('2026-10-25T22:59:00+00:00');
});

it('accepts a segment within the october daylight-saving week', function (): void {
    $timesheet = new Timesheet([
        'period_start' => '2026-10-19',
        'period_end' => '2026-10-25',
    ]);

    expect(fn () => TimesheetWeekBounds::assertContains(
        $timesheet,
        CarbonImmutable::parse('2026-10-25 22:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-10-25 23:00', 'Europe/Paris'),
    ))->not->toThrow(ValidationException::class);
});

it('refuses an instant after the october daylight-saving week', function (): void {
    $timesheet = new Timesheet([
        'period_start' => '2026-10-19',
        'period_end' => '2026-10-25',
    ]);

    expect(fn () => TimesheetWeekBounds::assertContains(
        $timesheet,
        CarbonImmutable::parse('2026-10-26 00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-10-26 01:00', 'Europe/Paris'),
    ))->toThrow(ValidationException::class);
});
