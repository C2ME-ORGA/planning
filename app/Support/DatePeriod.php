<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Analyse et comparaison strictes des dates métier (format `Y-m-d`).
 *
 * Les périodes sont inclusives. Une date de fin nulle signifie « ouverte ».
 */
final class DatePeriod
{
    public const OPEN_END = '9999-12-31';

    public static function parse(string $value): CarbonImmutable
    {
        $value = trim($value);

        // Les composants Filament peuvent fournir une date seule ou un instant
        // à minuit. Le métier ne conserve que la date civile.
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/',
            $value,
        ) !== 1) {
            throw new RuntimeException('La date doit être au format AAAA-MM-JJ.');
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== substr($value, 0, 10)) {
            throw new RuntimeException('La date doit être au format AAAA-MM-JJ.');
        }

        return $date;
    }

    public static function parseNullable(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::parse($value);
    }

    public static function assertOrder(CarbonInterface $start, ?CarbonInterface $end): void
    {
        if ($end !== null && $end->lt($start)) {
            throw new RuntimeException('La date de fin ne peut pas précéder la date de début.');
        }
    }

    public static function endOrMax(?CarbonInterface $end): string
    {
        return $end?->toDateString() ?? self::OPEN_END;
    }

    public static function format(?CarbonInterface $date): string
    {
        return $date?->format('d/m/Y') ?? 'sans date de fin';
    }
}
