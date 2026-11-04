<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Référence temporelle métier française.
 *
 * Les instants sont stockés en UTC ; leur projection locale et l'offset
 * applicable sont toujours dérivés de l'instant, jamais fournis séparément.
 */
final class BusinessTime
{
    public const TIMEZONE = 'Europe/Paris';

    public static function toLocal(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE);
    }

    /**
     * Offset local applicable à l'instant, en minutes à l'est d'UTC.
     */
    public static function offsetMinutesAt(CarbonInterface $instant): int
    {
        return intdiv(self::toLocal($instant)->getOffset(), 60);
    }
}
