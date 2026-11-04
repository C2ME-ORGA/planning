<?php

use App\Enums\TimeCategory;
use App\Services\Hours\IntervalMerger;
use App\Support\TimeInterval;
use Carbon\CarbonImmutable;
use Tests\Support\HoursFixtures;

function pc34IntervalMerger(): IntervalMerger
{
    return app(IntervalMerger::class);
}

it('keeps disjoint intervals separate', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 10:00:00', 1),
        HoursFixtures::interval('2026-11-02 12:00:00', '2026-11-02 14:00:00', 2),
    ]);

    expect($result['intervals'])->toHaveCount(2)
        ->and(array_sum(array_map(
            static fn (TimeInterval $interval): int => $interval->minutes(),
            $result['intervals'],
        )))->toBe(240)
        ->and($result['overlaps'])->toBe([]);
});

it('merges adjacent intervals without reporting an anomaly', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 1),
        HoursFixtures::interval('2026-11-02 12:00:00', '2026-11-02 14:00:00', 2),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(360)
        ->and($result['overlaps'])->toBe([]);
});

it('counts a partial overlap only once and identifies both segments', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 10),
        HoursFixtures::interval('2026-11-02 10:00:00', '2026-11-02 14:00:00', 11),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(360)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['overlaps'][0]['segment_ids'])->toBe([10, 11])
        ->and($result['overlaps'][0]['minutes'])->toBe(120);
});

it('counts a fully contained interval only once', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 20),
        HoursFixtures::interval('2026-11-02 09:00:00', '2026-11-02 10:00:00', 21),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(240)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['overlaps'][0]['segment_ids'])->toBe([20, 21]);
});

it('counts identical intervals only once', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 30),
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 31),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(240)
        ->and($result['overlaps'])->toHaveCount(1);
});

it('merges a chain of overlapping intervals and reports each overlapping pair', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 40),
        HoursFixtures::interval('2026-11-02 10:00:00', '2026-11-02 14:00:00', 41),
        HoursFixtures::interval('2026-11-02 13:00:00', '2026-11-02 15:00:00', 42),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(420)
        ->and($result['overlaps'])->toHaveCount(2);
});

it('produces the same result when input order is not sorted', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 10:00:00', '2026-11-02 14:00:00', 51),
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 50),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->start()->toIso8601String())->toBe('2026-11-02T07:00:00+00:00')
        ->and($result['intervals'][0]->end()->toIso8601String())->toBe('2026-11-02T13:00:00+00:00')
        ->and($result['intervals'][0]->minutes())->toBe(360);
});

it('merges overlapping intervals across a clock change', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-03-29 00:00:00', '2026-03-29 04:00:00', 60),
        HoursFixtures::interval('2026-03-29 03:00:00', '2026-03-29 05:00:00', 61),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(240)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['overlaps'][0]['minutes'])->toBe(60);
});

it('reports each overlapping pair in a chain of three intervals', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 70),
        HoursFixtures::interval('2026-11-02 10:00:00', '2026-11-02 14:00:00', 71),
        HoursFixtures::interval('2026-11-02 13:00:00', '2026-11-02 15:00:00', 72),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['intervals'][0]->minutes())->toBe(420)
        ->and($result['overlaps'])->toHaveCount(2)
        ->and($result['overlaps'][0]['segment_ids'])->toBe([70, 71])
        ->and($result['overlaps'][1]['segment_ids'])->toBe([71, 72]);
});

it('includes timesheet ids and category in overlap diagnostics', function (): void {
    $result = pc34IntervalMerger()->merge([
        TimeInterval::between(
            CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
            CarbonImmutable::parse('2026-11-02 12:00:00', 'Europe/Paris'),
            80,
            100,
            TimeCategory::TravailEffectif,
        ),
        TimeInterval::between(
            CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Paris'),
            CarbonImmutable::parse('2026-11-02 14:00:00', 'Europe/Paris'),
            81,
            100,
            TimeCategory::TravailEffectif,
        ),
    ]);

    expect($result['overlaps'])->toHaveCount(1)
        ->and($result['overlaps'][0]['segment_ids'])->toBe([80, 81])
        ->and($result['overlaps'][0]['timesheet_ids'])->toBe([100])
        ->and($result['overlaps'][0]['category'])->toBe('travail_effectif')
        ->and($result['overlaps'][0]['minutes'])->toBe(120);
});

it('never loses overlap diagnostics when merging', function (): void {
    $result = pc34IntervalMerger()->merge([
        HoursFixtures::interval('2026-11-02 08:00:00', '2026-11-02 12:00:00', 90),
        HoursFixtures::interval('2026-11-02 10:00:00', '2026-11-02 14:00:00', 91),
        HoursFixtures::interval('2026-11-02 13:00:00', '2026-11-02 15:00:00', 92),
    ]);

    expect($result['intervals'])->toHaveCount(1)
        ->and($result['overlaps'])->toHaveCount(2);
});
