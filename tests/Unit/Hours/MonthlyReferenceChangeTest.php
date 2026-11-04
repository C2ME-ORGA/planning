<?php

use App\Exceptions\BusinessRuleException;
use App\Services\Hours\MonthlyReferenceChange;
use Carbon\CarbonImmutable;

it('computes the first day of the next month for a mid-month modification', function (): void {
    $change = MonthlyReferenceChange::fromModificationDate('2026-11-15', 9000);

    expect($change->effectiveFrom()->toDateString())->toBe('2026-12-01')
        ->and($change->referenceMinutes())->toBe(9000);
});

it('computes the first day of the next month for a modification on the first day', function (): void {
    $change = MonthlyReferenceChange::fromModificationDate('2026-11-01', 9000);

    expect($change->effectiveFrom()->toDateString())->toBe('2026-12-01');
});

it('computes the first day of the next month for a modification on the last day', function (): void {
    $change = MonthlyReferenceChange::fromModificationDate('2026-11-30', 9000);

    expect($change->effectiveFrom()->toDateString())->toBe('2026-12-01');
});

it('computes the first day of January for a December modification', function (): void {
    $change = MonthlyReferenceChange::fromModificationDate('2026-12-15', 9000);

    expect($change->effectiveFrom()->toDateString())->toBe('2027-01-01');
});

it('computes the first day of January for a modification on December 31', function (): void {
    $change = MonthlyReferenceChange::fromModificationDate('2026-12-31', 9000);

    expect($change->effectiveFrom()->toDateString())->toBe('2027-01-01');
});

it('rejects a retroactive modification', function (): void {
    $now = CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Paris');

    expect(fn () => MonthlyReferenceChange::fromModificationDate(
        '2026-10-15',
        9000,
        $now,
    ))->toThrow(BusinessRuleException::class);
});

it('rejects a modification whose effect would be retroactive in January', function (): void {
    $now = CarbonImmutable::parse('2027-01-15', 'Europe/Paris');

    expect(fn () => MonthlyReferenceChange::fromModificationDate(
        '2026-12-15',
        9000,
        $now,
    ))->toThrow(BusinessRuleException::class);
});

it('accepts a modification whose effect is the current month when made before the first day', function (): void {
    // Modification le 31 octobre pour effet au 1er novembre, alors qu'on est
    // encore en octobre : ce n'est pas rétroactif.
    $now = CarbonImmutable::parse('2026-10-31', 'Europe/Paris');

    $change = MonthlyReferenceChange::fromModificationDate('2026-10-31', 9000, $now);

    expect($change->effectiveFrom()->toDateString())->toBe('2026-11-01');
});

it('rejects a negative reference', function (): void {
    expect(fn () => MonthlyReferenceChange::fromModificationDate('2026-11-15', -1))
        ->toThrow(BusinessRuleException::class);
});

it('rejects a zero reference', function (): void {
    expect(fn () => MonthlyReferenceChange::fromModificationDate('2026-11-15', 0))
        ->toThrow(BusinessRuleException::class);
});
