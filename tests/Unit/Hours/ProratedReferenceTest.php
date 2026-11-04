<?php

use App\Exceptions\BusinessRuleException;
use App\Services\Hours\ProratedReference;

it('returns the standard reference without rounding for a full month', function (): void {
    $result = ProratedReference::calculate(10140, 30, 30);

    expect($result['reference_minutes'])->toBe(10140)
        ->and($result['is_prorated'])->toBeFalse()
        ->and($result['exact_numerator'])->toBe(10140)
        ->and($result['exact_denominator'])->toBe(1);
});

it('prorates an entry in the middle of november', function (): void {
    $result = ProratedReference::calculate(10140, 15, 30);

    expect($result['reference_minutes'])->toBe(5070)
        ->and($result['is_prorated'])->toBeTrue()
        ->and($result['exact_numerator'])->toBe(152100)
        ->and($result['exact_denominator'])->toBe(30);
});

it('prorates an exit in the middle of november', function (): void {
    $result = ProratedReference::calculate(10140, 15, 30);

    expect($result['reference_minutes'])->toBe(5070);
});

it('rounds a fractional prorated reference to the nearest quarter hour', function (): void {
    $result = ProratedReference::calculate(10140, 15, 31);

    expect($result['reference_minutes'])->toBe(4905)
        ->and($result['exact_numerator'])->toBe(152100)
        ->and($result['exact_denominator'])->toBe(31);
});

it('rounds down when the remainder is seven minutes or less', function (): void {
    expect(ProratedReference::roundToQuarterHour(1012, 1))->toBe(1005)
        ->and(ProratedReference::roundToQuarterHour(1011, 1))->toBe(1005);
});

it('rounds up when the remainder is eight minutes or more', function (): void {
    expect(ProratedReference::roundToQuarterHour(1013, 1))->toBe(1020)
        ->and(ProratedReference::roundToQuarterHour(1014, 1))->toBe(1020);
});

it('uses the half-quarter threshold at seven minutes thirty seconds', function (): void {
    expect(ProratedReference::roundToQuarterHour(2024, 2))->toBe(1005)
        ->and(ProratedReference::roundToQuarterHour(2025, 2))->toBe(1020);
});

it('rounds down at exactly seven minutes', function (): void {
    // 1012 minutes exactement : reste de 7 minutes après 1005.
    expect(ProratedReference::roundToQuarterHour(1012, 1))->toBe(1005);
});

it('rounds up at seven minutes and one second', function (): void {
    // 1012 min 1 s = 60721 secondes sur 60 : reste de 1 seconde au-delà de 7 min.
    expect(ProratedReference::roundToQuarterHour(60721, 60))->toBe(1020);
});

it('rounds up at seven minutes thirty seconds', function (): void {
    // 1012 min 30 s = 60750 secondes sur 60.
    expect(ProratedReference::roundToQuarterHour(60750, 60))->toBe(1020);
});

it('rounds up at seven minutes fifty-nine seconds', function (): void {
    // 1012 min 59 s = 60779 secondes sur 60.
    expect(ProratedReference::roundToQuarterHour(60779, 60))->toBe(1020);
});

it('rounds up at eight minutes', function (): void {
    // 1013 minutes : reste de 8 minutes après 1005.
    expect(ProratedReference::roundToQuarterHour(1013, 1))->toBe(1020);
});

it('does not round a full-month individual reference', function (): void {
    $result = ProratedReference::calculate(10000, 31, 31);

    expect($result['reference_minutes'])->toBe(10000)
        ->and($result['is_prorated'])->toBeFalse();
});

it('returns zero when presence is zero', function (): void {
    $result = ProratedReference::calculate(10140, 0, 31);

    expect($result['reference_minutes'])->toBe(0)
        ->and($result['is_prorated'])->toBeTrue();
});

it('rejects inconsistent presence days', function (): void {
    expect(fn () => ProratedReference::calculate(10140, 32, 31))
        ->toThrow(BusinessRuleException::class);
});
