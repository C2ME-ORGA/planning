<?php

use App\Enums\TimeCategory;
use App\Models\TimesheetSegment;
use App\Services\Hours\WeeklyHoursCalculator;
use Carbon\CarbonImmutable;
use Tests\Support\HoursFixtures;

function pc34WeeklyCalculator(): WeeklyHoursCalculator
{
    return app(WeeklyHoursCalculator::class);
}

function pc34SegmentWithMinutes(int $minutes, string $start = '2026-11-02 00:00:00'): TimesheetSegment
{
    $startAt = CarbonImmutable::parse($start, 'Europe/Paris');
    $endAt = $startAt->addMinutes($minutes);

    return HoursFixtures::segment(
        $startAt->format('Y-m-d H:i:s'),
        $endAt->format('Y-m-d H:i:s'),
    );
}

it('applies the 39-hour weekly threshold exactly', function (int $minutes, int $overtime, int $deficit): void {
    $result = pc34WeeklyCalculator()->calculate(
        [pc34SegmentWithMinutes($minutes)],
        CarbonImmutable::parse('2026-11-02', 'Europe/Paris'),
    );

    expect($result['effective_minutes'])->toBe($minutes)
        ->and($result['threshold_minutes'])->toBe(2340)
        ->and($result['overtime_minutes'])->toBe($overtime)
        ->and($result['deficit_minutes'])->toBe($deficit);
})->with([
    '38 h 59' => [2339, 0, 1],
    '39 h exactement' => [2340, 0, 0],
    '39 h 01' => [2341, 1, 0],
    '40 h' => [2400, 60, 0],
    '42 h' => [2520, 180, 0],
]);

it('keeps worked time at minute precision without rounding overtime', function (): void {
    $result = pc34WeeklyCalculator()->calculate(
        [pc34SegmentWithMinutes(2401)],
        CarbonImmutable::parse('2026-11-02', 'Europe/Paris'),
    );

    expect($result['effective_minutes'])->toBe(2401)
        ->and($result['overtime_minutes'])->toBe(61);
});

it('excludes non-effective categories from the weekly threshold', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        pc34SegmentWithMinutes(2400),
        HoursFixtures::segment(
            '2026-11-03 08:00:00',
            '2026-11-03 10:00:00',
            TimeCategory::Trajet,
        ),
    ], CarbonImmutable::parse('2026-11-02', 'Europe/Paris'));

    expect($result['effective_minutes'])->toBe(2400)
        ->and($result['overtime_minutes'])->toBe(60);
});

it('merges overlapping effective segments before applying the threshold', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::TravailEffectif, 1),
        HoursFixtures::segment('2026-11-02 10:00:00', '2026-11-02 14:00:00', TimeCategory::TravailEffectif, 2),
    ], CarbonImmutable::parse('2026-11-02', 'Europe/Paris'));

    expect($result['effective_minutes'])->toBe(360)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['deficit_minutes'])->toBe(1980);
});

it('accounts for the spring clock change in the weekly total', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        HoursFixtures::segment('2026-03-29 00:00:00', '2026-03-29 04:00:00'),
    ], CarbonImmutable::parse('2026-03-23', 'Europe/Paris'));

    expect($result['effective_minutes'])->toBe(180)
        ->and($result['deficit_minutes'])->toBe(2160);
});

it('accounts for the autumn clock change in the weekly total', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        HoursFixtures::segment('2026-10-25 00:00:00', '2026-10-25 04:00:00'),
    ], CarbonImmutable::parse('2026-10-19', 'Europe/Paris'));

    expect($result['effective_minutes'])->toBe(300)
        ->and($result['deficit_minutes'])->toBe(2040);
});

it('computes a week crossing two months without changing the weekly rule', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        HoursFixtures::segment('2026-10-31 22:00:00', '2026-11-01 02:00:00'),
    ], CarbonImmutable::parse('2026-10-26', 'Europe/Paris'));

    expect($result['week_start'])->toBe('2026-10-26')
        ->and($result['week_end'])->toBe('2026-11-01')
        ->and($result['effective_minutes'])->toBe(240)
        ->and($result['deficit_minutes'])->toBe(2100);
});

it('includes the last minute of Sunday and excludes the next Monday', function (): void {
    $result = pc34WeeklyCalculator()->calculate([
        HoursFixtures::segment('2026-11-08 23:00:00', '2026-11-08 23:59:00'),
        HoursFixtures::segment('2026-11-09 00:00:00', '2026-11-09 01:00:00'),
    ], CarbonImmutable::parse('2026-11-02', 'Europe/Paris'));

    expect($result['effective_minutes'])->toBe(59)
        ->and($result['by_day'])->toBe(['2026-11-08' => 59]);
});

it('does not report a weekly deficit on the following week', function (): void {
    $underThreshold = pc34WeeklyCalculator()->calculate(
        [pc34SegmentWithMinutes(2339, '2026-11-02 00:00:00')],
        CarbonImmutable::parse('2026-11-02', 'Europe/Paris'),
    );

    $overThreshold = pc34WeeklyCalculator()->calculate(
        [pc34SegmentWithMinutes(2400, '2026-11-09 00:00:00')],
        CarbonImmutable::parse('2026-11-09', 'Europe/Paris'),
    );

    expect($underThreshold['deficit_minutes'])->toBe(1)
        ->and($overThreshold['overtime_minutes'])->toBe(60)
        ->and($overThreshold['deficit_minutes'])->toBe(0);
});
