<?php

use App\Support\DatePeriod;

it('parses business dates and normalizes Filament datetime values', function () {
    expect(DatePeriod::parse('2026-06-30')->toDateString())->toBe('2026-06-30')
        ->and(DatePeriod::parse('2026-06-30 00:00:00')->toDateString())->toBe('2026-06-30')
        ->and(DatePeriod::parse('2026-06-30T00:00:00Z')->toDateString())->toBe('2026-06-30')
        ->and(DatePeriod::parseNullable(null))->toBeNull()
        ->and(DatePeriod::parseNullable(''))->toBeNull();
});

it('rejects invalid or inverted dates', function () {
    expect(fn () => DatePeriod::parse('30/06/2026'))->toThrow(RuntimeException::class)
        ->and(fn () => DatePeriod::assertOrder(
            DatePeriod::parse('2026-06-30'),
            DatePeriod::parse('2026-06-29'),
        ))->toThrow(RuntimeException::class, 'ne peut pas précéder');
});
