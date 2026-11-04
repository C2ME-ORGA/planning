<?php

use App\Services\Hours\EmploymentPresence;
use Carbon\CarbonImmutable;

function pc34EmploymentPresence(): EmploymentPresence
{
    return app(EmploymentPresence::class);
}

it('counts all calendar days for a fully covered month', function (string $month, int $days): void {
    $result = pc34EmploymentPresence()->forRanges([
        ['start' => $month.'-01', 'end' => null],
    ], CarbonImmutable::parse($month.'-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe($days)
        ->and($result['days_in_month'])->toBe($days)
        ->and($result['has_employment'])->toBeTrue();
})->with([
    'février 2026' => ['2026-02', 28],
    'février 2028 bissextile' => ['2028-02', 29],
    'avril 2026' => ['2026-04', 30],
    'mai 2026' => ['2026-05', 31],
]);

it('counts an entry in the middle of the month inclusively', function (): void {
    $result = pc34EmploymentPresence()->forRanges([
        ['start' => '2026-11-16', 'end' => null],
    ], CarbonImmutable::parse('2026-11-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe(15)
        ->and($result['days_in_month'])->toBe(30);
});

it('counts an exit in the middle of the month inclusively', function (): void {
    $result = pc34EmploymentPresence()->forRanges([
        ['start' => '2026-01-01', 'end' => '2026-11-15'],
    ], CarbonImmutable::parse('2026-11-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe(15);
});

it('unions multiple employment periods without double counting', function (): void {
    $result = pc34EmploymentPresence()->forRanges([
        ['start' => '2026-11-01', 'end' => '2026-11-10'],
        ['start' => '2026-11-16', 'end' => '2026-11-30'],
    ], CarbonImmutable::parse('2026-11-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe(25);
});

it('returns no employment when no period covers the month', function (): void {
    $result = pc34EmploymentPresence()->forRanges([
        ['start' => '2025-01-01', 'end' => '2025-12-31'],
    ], CarbonImmutable::parse('2026-11-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe(0)
        ->and($result['has_employment'])->toBeFalse();
});

it('returns no employment when no period exists', function (): void {
    $result = pc34EmploymentPresence()->forRanges([], CarbonImmutable::parse('2026-11-01', 'Europe/Paris'));

    expect($result['presence_days'])->toBe(0)
        ->and($result['has_employment'])->toBeFalse();
});
