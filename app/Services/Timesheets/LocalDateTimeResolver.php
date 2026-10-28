<?php

namespace App\Services\Timesheets;

use App\Support\BusinessTime;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Résolution d'une heure locale française en instant UTC non ambigu.
 *
 * Le fuseau métier est `Europe/Paris`. Lors d'un changement d'heure :
 * - une heure locale inexistante (passage à l'heure d'été) est refusée ;
 * - une heure locale ambiguë (passage à l'heure d'hiver) exige un offset
 *   explicite, exprimé en minutes à l'est d'UTC (+120 en été, +60 en hiver).
 *
 * Aucun arrondi n'est effectué : les secondes doivent être nulles.
 */
final class LocalDateTimeResolver
{
    public const TIMEZONE = BusinessTime::TIMEZONE;

    public function resolve(string $date, string $time, ?int $offsetMinutes = null): CarbonImmutable
    {
        $date = DatePeriod::parse(trim($date))->toDateString();
        $time = $this->normalizeTime(trim($time));

        $wallClock = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, 'UTC');

        if (! $wallClock instanceof CarbonImmutable) {
            throw new RuntimeException('La date et l’heure locales sont invalides.');
        }

        $matches = $this->matchingInstants($wallClock->getTimestamp(), $date, $time);

        if ($matches === []) {
            throw new RuntimeException(sprintf(
                'L’heure locale %s %s n’existe pas dans le fuseau %s (changement d’heure).',
                $date,
                $time,
                self::TIMEZONE,
            ));
        }

        if (count($matches) > 1) {
            if ($offsetMinutes === null) {
                throw new RuntimeException(sprintf(
                    'L’heure locale %s %s est ambiguë dans le fuseau %s ; précisez l’offset en minutes.',
                    $date,
                    $time,
                    self::TIMEZONE,
                ));
            }

            if (! array_key_exists($offsetMinutes, $matches)) {
                throw new RuntimeException(sprintf(
                    'L’offset %d minutes ne correspond pas à l’heure locale %s %s.',
                    $offsetMinutes,
                    $date,
                    $time,
                ));
            }

            return $matches[$offsetMinutes];
        }

        $instant = $matches[array_key_first($matches)];

        if ($offsetMinutes !== null && ! array_key_exists($offsetMinutes, $matches)) {
            throw new RuntimeException(sprintf(
                'L’offset %d minutes ne correspond pas à l’heure locale %s %s.',
                $offsetMinutes,
                $date,
                $time,
            ));
        }

        return $instant;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::00)?$/', $time) !== 1) {
            throw new RuntimeException('L’heure doit être au format HH:MM, sans seconde non nulle.');
        }

        return substr($time, 0, 5);
    }

    /**
     * @return array<int, CarbonImmutable> instants UTC indexés par offset en minutes
     */
    private function matchingInstants(int $wallTimestamp, string $date, string $time): array
    {
        $timezone = new DateTimeZone(BusinessTime::TIMEZONE);
        $transitions = $timezone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);

        $offsets = [];

        foreach ($transitions as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }

        $matches = [];

        foreach (array_keys($offsets) as $offsetSeconds) {
            $instant = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $offsetSeconds);

            if ($instant->setTimezone(BusinessTime::TIMEZONE)->format('Y-m-d H:i') === $date.' '.$time) {
                $matches[intdiv($offsetSeconds, 60)] = $instant->utc();
            }
        }

        ksort($matches);

        return $matches;
    }
}
