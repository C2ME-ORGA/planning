<?php

use App\Enums\TimeCategory;
use App\Services\Hours\CategoryIntervals;
use Carbon\CarbonImmutable;
use Tests\Support\HoursFixtures;

function pc34CategoryIntervals(): CategoryIntervals
{
    return app(CategoryIntervals::class);
}

it('counts only effective work for the effective category', function (): void {
    $result = pc34CategoryIntervals()->build(TimeCategory::TravailEffectif, [
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00'),
        HoursFixtures::segment('2026-11-02 12:00:00', '2026-11-02 14:00:00', TimeCategory::Pause),
    ]);

    expect($result['total_minutes'])->toBe(240)
        ->and($result['daily_minutes'])->toBe(['2026-11-02' => 240])
        ->and($result['overlaps'])->toBe([]);
});

it('excludes each non-effective category from effective work', function (TimeCategory $category): void {
    $result = pc34CategoryIntervals()->build(TimeCategory::TravailEffectif, [
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00', $category),
    ]);

    expect($result['total_minutes'])->toBe(0)
        ->and($result['daily_minutes'])->toBe([]);
})->with([
    'trajet' => TimeCategory::Trajet,
    'pause' => TimeCategory::Pause,
    'formation' => TimeCategory::Formation,
    'autre' => TimeCategory::Autre,
]);

it('merges overlapping effective segments before counting them', function (): void {
    $result = pc34CategoryIntervals()->build(TimeCategory::TravailEffectif, [
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::TravailEffectif, 1),
        HoursFixtures::segment('2026-11-02 10:00:00', '2026-11-02 14:00:00', TimeCategory::TravailEffectif, 2),
    ]);

    expect($result['total_minutes'])->toBe(360)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['overlaps'][0]['segment_ids'])->toBe([1, 2]);
});

it('merges categories independently and never across categories', function (): void {
    $segments = [
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::TravailEffectif),
        HoursFixtures::segment('2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::Pause),
        HoursFixtures::segment('2026-11-02 09:00:00', '2026-11-02 10:00:00', TimeCategory::Pause),
    ];

    $effective = pc34CategoryIntervals()->build(TimeCategory::TravailEffectif, $segments);
    $pause = pc34CategoryIntervals()->build(TimeCategory::Pause, $segments);

    expect($effective['total_minutes'])->toBe(240)
        ->and($pause['total_minutes'])->toBe(240)
        ->and($pause['overlaps'])->toHaveCount(1);
});

it('splits effective minutes across midnight without double counting', function (): void {
    $result = pc34CategoryIntervals()->build(TimeCategory::TravailEffectif, [
        HoursFixtures::segment('2026-11-02 22:00:00', '2026-11-03 02:00:00'),
    ]);

    expect($result['total_minutes'])->toBe(240)
        ->and($result['daily_minutes'])->toBe([
            '2026-11-02' => 120,
            '2026-11-03' => 120,
        ]);
});

it('clips intervals to the requested window', function (): void {
    $result = pc34CategoryIntervals()->build(
        TimeCategory::TravailEffectif,
        [
            HoursFixtures::segment('2026-10-31 22:00:00', '2026-11-01 02:00:00'),
        ],
        new CarbonImmutable('2026-11-01 00:00:00', 'Europe/Paris'),
        new CarbonImmutable('2026-12-01 00:00:00', 'Europe/Paris'),
    );

    expect($result['total_minutes'])->toBe(120)
        ->and($result['daily_minutes'])->toBe(['2026-11-01' => 120]);
});

it('returns an empty result when no segment matches the category', function (): void {
    $result = pc34CategoryIntervals()->build(TimeCategory::Formation, []);

    expect($result['total_minutes'])->toBe(0)
        ->and($result['daily_minutes'])->toBe([])
        ->and($result['overlaps'])->toBe([]);
});
