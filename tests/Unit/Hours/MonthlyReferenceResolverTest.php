<?php

use App\Services\Hours\MonthlyReferenceChange;
use App\Services\Hours\MonthlyReferenceResolver;
use Carbon\CarbonImmutable;
use Tests\Support\ArrayMonthlyReferenceProvider;
use Tests\Support\HoursFixtures;

it('uses the 169-hour default when no individual reference exists', function (): void {
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([]));

    $minutes = $resolver->baseMinutesFor(
        HoursFixtures::employee(),
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($minutes)->toBe(10140);
});

it('uses an individual reference applicable to the month', function (): void {
    $employee = HoursFixtures::employee();
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-11-01', 9000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-11-01', 'Europe/Paris')))
        ->toBe(9000)
        ->and($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-10-01', 'Europe/Paris')))
        ->toBe(10140);
});

it('applies a change made in november only from december', function (): void {
    $employee = HoursFixtures::employee();
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-12-01', 9000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-11-15', 'Europe/Paris')))
        ->toBe(10140)
        ->and($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-12-01', 'Europe/Paris')))
        ->toBe(9000);
});

it('keeps the old reference for the current month and applies the new one next month', function (): void {
    $employee = HoursFixtures::employee();
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-11-01', 9000),
            MonthlyReferenceChange::forMonth('2026-12-01', 10000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-11-01', 'Europe/Paris')))
        ->toBe(9000)
        ->and($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-12-01', 'Europe/Paris')))
        ->toBe(10000);
});

it('lets a later future change replace an earlier future change for the same effective month', function (): void {
    $employee = HoursFixtures::employee();
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-12-01', 9000),
            MonthlyReferenceChange::forMonth('2026-12-01', 10000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-12-01', 'Europe/Paris')))
        ->toBe(10000);
});

it('resolves deterministically when two changes share the same effective month in reverse order', function (): void {
    $employee = HoursFixtures::employee();

    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-12-01', 10000),
            MonthlyReferenceChange::forMonth('2026-12-01', 9000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-12-01', 'Europe/Paris')))
        ->toBe(9000);
});

it('does not apply a future reference retroactively', function (): void {
    $employee = HoursFixtures::employee();
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2027-01-01', 8000),
        ],
    ]));

    expect($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2026-12-01', 'Europe/Paris')))
        ->toBe(10140)
        ->and($resolver->baseMinutesFor($employee, CarbonImmutable::parse('2027-01-01', 'Europe/Paris')))
        ->toBe(8000);
});

it('resolves references independently for each employee', function (): void {
    $first = HoursFixtures::employee(id: 1);
    $second = HoursFixtures::employee(id: 2);
    $resolver = new MonthlyReferenceResolver(new ArrayMonthlyReferenceProvider([
        1 => [MonthlyReferenceChange::forMonth('2026-11-01', 8000)],
        2 => [MonthlyReferenceChange::forMonth('2026-11-01', 9000)],
    ]));

    expect($resolver->baseMinutesFor($first, CarbonImmutable::parse('2026-11-01', 'Europe/Paris')))
        ->toBe(8000)
        ->and($resolver->baseMinutesFor($second, CarbonImmutable::parse('2026-11-01', 'Europe/Paris')))
        ->toBe(9000);
});
