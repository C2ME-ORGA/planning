<?php

use App\Services\Timesheets\LocalDateTimeResolver;
use App\Services\Timesheets\WorkedTimeCalculator;
use Carbon\CarbonImmutable;

function parisLocalTime(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value, LocalDateTimeResolver::TIMEZONE);
}

it('splits a segment crossing midnight without double counting', function (): void {
    $result = app(WorkedTimeCalculator::class)->evaluate(
        parisLocalTime('2026-11-02 22:00:00'),
        parisLocalTime('2026-11-03 02:00:00'),
    );

    expect($result['total_minutes'])->toBe(240)
        ->and($result['daily_minutes'])->toBe([
            '2026-11-02' => 120,
            '2026-11-03' => 120,
        ])
        ->and(array_sum($result['daily_minutes']))->toBe($result['total_minutes'])
        ->and($result['warnings'])->toBe([]);
});

it('warns only for a segment strictly longer than twelve hours', function (): void {
    $calculator = app(WorkedTimeCalculator::class);

    $exactlyTwelve = $calculator->evaluate(
        parisLocalTime('2026-11-02 08:00:00'),
        parisLocalTime('2026-11-02 20:00:00'),
    );

    $overTwelve = $calculator->evaluate(
        parisLocalTime('2026-11-02 08:00:00'),
        parisLocalTime('2026-11-02 20:01:00'),
    );

    expect($exactlyTwelve['total_minutes'])->toBe(720)
        ->and($exactlyTwelve['warnings'])->toBe([])
        ->and($overTwelve['total_minutes'])->toBe(721)
        ->and($overTwelve['warnings'])->toHaveCount(1)
        ->and($overTwelve['warnings'][0]['type'])->toBe('segment_over_12_hours')
        ->and($overTwelve['warnings'][0]['minutes'])->toBe(721);
});

it('accounts for the spring clock change when splitting by civil day', function (): void {
    $result = app(WorkedTimeCalculator::class)->evaluate(
        parisLocalTime('2026-03-29 00:00:00'),
        parisLocalTime('2026-03-29 04:00:00'),
    );

    expect($result['total_minutes'])->toBe(180)
        ->and($result['daily_minutes'])->toBe(['2026-03-29' => 180]);
});

it('accounts for the autumn clock change when splitting by civil day', function (): void {
    $result = app(WorkedTimeCalculator::class)->evaluate(
        parisLocalTime('2026-10-25 00:00:00'),
        parisLocalTime('2026-10-25 04:00:00'),
    );

    expect($result['total_minutes'])->toBe(300)
        ->and($result['daily_minutes'])->toBe(['2026-10-25' => 300]);
});

it('splits a segment that crosses a clock change and midnight', function (): void {
    $result = app(WorkedTimeCalculator::class)->evaluate(
        parisLocalTime('2026-10-24 22:00:00'),
        parisLocalTime('2026-10-25 04:00:00'),
    );

    expect($result['total_minutes'])->toBe(420)
        ->and($result['daily_minutes'])->toBe([
            '2026-10-24' => 120,
            '2026-10-25' => 300,
        ]);
});

it('rejects a zero or negative duration', function (): void {
    $calculator = app(WorkedTimeCalculator::class);

    expect(fn () => $calculator->evaluate(
        parisLocalTime('2026-11-02 08:00:00'),
        parisLocalTime('2026-11-02 08:00:00'),
    ))->toThrow(RuntimeException::class);

    expect(fn () => $calculator->evaluate(
        parisLocalTime('2026-11-02 09:00:00'),
        parisLocalTime('2026-11-02 08:00:00'),
    ))->toThrow(RuntimeException::class);

    expect(fn () => $calculator->dailyMinutes(
        parisLocalTime('2026-11-02 09:00:00'),
        parisLocalTime('2026-11-02 08:00:00'),
    ))->toThrow(RuntimeException::class);
});

it('rejects seconds and fractions of a minute', function (): void {
    $calculator = app(WorkedTimeCalculator::class);

    expect(fn () => $calculator->evaluate(
        parisLocalTime('2026-11-02 08:00:30'),
        parisLocalTime('2026-11-02 09:00:00'),
    ))->toThrow(RuntimeException::class, 'minute');

    expect(fn () => $calculator->evaluate(
        parisLocalTime('2026-11-02 08:00:00.500000'),
        parisLocalTime('2026-11-02 09:00:00'),
    ))->toThrow(RuntimeException::class, 'minute');
});
