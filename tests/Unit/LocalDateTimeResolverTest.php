<?php

use App\Services\Timesheets\LocalDateTimeResolver;

it('resolves a summer local time to UTC', function (): void {
    $instant = app(LocalDateTimeResolver::class)->resolve('2026-07-01', '08:00');

    expect($instant->format('Y-m-d H:i'))->toBe('2026-07-01 06:00')
        ->and($instant->getOffset())->toBe(0);
});

it('resolves a winter local time to UTC', function (): void {
    $instant = app(LocalDateTimeResolver::class)->resolve('2026-12-01', '08:00');

    expect($instant->format('Y-m-d H:i'))->toBe('2026-12-01 07:00')
        ->and($instant->getOffset())->toBe(0);
});

it('rejects a local time that does not exist during the spring transition', function (): void {
    expect(fn () => app(LocalDateTimeResolver::class)->resolve('2026-03-29', '02:30'))
        ->toThrow(RuntimeException::class, 'n’existe pas');
});

it('requires an explicit offset for an ambiguous autumn local time', function (): void {
    $resolver = app(LocalDateTimeResolver::class);

    expect(fn () => $resolver->resolve('2026-10-25', '02:30'))
        ->toThrow(RuntimeException::class, 'ambiguë');

    expect($resolver->resolve('2026-10-25', '02:30', 120)->format('Y-m-d H:i'))
        ->toBe('2026-10-25 00:30')
        ->and($resolver->resolve('2026-10-25', '02:30', 60)->format('Y-m-d H:i'))
        ->toBe('2026-10-25 01:30');
});

it('rejects an offset that does not match the local time', function (): void {
    $resolver = app(LocalDateTimeResolver::class);

    expect(fn () => $resolver->resolve('2026-07-01', '08:00', 60))
        ->toThrow(RuntimeException::class, 'ne correspond pas');

    expect(fn () => $resolver->resolve('2026-10-25', '02:30', 180))
        ->toThrow(RuntimeException::class, 'ne correspond pas');
});

it('rejects invalid dates, times and non-zero seconds', function (string $date, string $time): void {
    expect(fn () => app(LocalDateTimeResolver::class)->resolve($date, $time))
        ->toThrow(RuntimeException::class);
})->with([
    'date impossible' => ['2026-02-30', '08:00'],
    'heure invalide' => ['2026-07-01', '24:00'],
    'secondes non nulles' => ['2026-07-01', '08:00:30'],
]);
