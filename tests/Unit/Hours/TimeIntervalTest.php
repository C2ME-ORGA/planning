<?php

use App\Exceptions\BusinessRuleException;
use App\Support\TimeInterval;
use Carbon\CarbonImmutable;

it('computes a valid intersection', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
    );

    $clipped = $interval->clip(
        CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
    );

    expect($clipped)->not->toBeNull()
        ->and($clipped->start()->toIso8601String())->toBe('2026-11-02T09:00:00+00:00')
        ->and($clipped->end()->toIso8601String())->toBe('2026-11-02T11:00:00+00:00')
        ->and($clipped->minutes())->toBe(120);
});

it('returns null when there is no intersection', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
    );

    $clipped = $interval->clip(
        CarbonImmutable::parse('2026-11-02 13:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
    );

    expect($clipped)->toBeNull();
});

it('rejects a window with seconds on the lower bound', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
    );

    expect(fn () => $interval->clip(
        CarbonImmutable::parse('2026-11-02 10:00:30', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
    ))->toThrow(BusinessRuleException::class);
});

it('rejects a window with microseconds on the upper bound', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
    );

    $upper = CarbonImmutable::createFromFormat(
        'Y-m-d H:i:s.u',
        '2026-11-02 13:30:00.500000',
        'Europe/Paris',
    );

    expect(fn () => $interval->clip(
        CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Paris'),
        $upper,
    ))->toThrow(BusinessRuleException::class);
});

it('rejects a non-positive window', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
    );

    expect(fn () => $interval->clip(
        CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 13:00:00', 'Europe/Paris'),
    ))->toThrow(BusinessRuleException::class);
});

it('rejects a window ending exactly at the start', function (): void {
    $interval = TimeInterval::between(
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
    );

    $clipped = $interval->clip(
        CarbonImmutable::parse('2026-11-01 08:00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
    );

    expect($clipped)->toBeNull();
});
