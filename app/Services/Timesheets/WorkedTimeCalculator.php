<?php

namespace App\Services\Timesheets;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;
use RuntimeException;

/**
 * Calculs de durée d'un segment de relevé.
 *
 * Les calculs se font en minutes entières, sur des instants non ambigus. La
 * répartition par date civile utilise le fuseau `Europe/Paris` et tient compte
 * des changements d'heure : la somme des minutes journalières reste égale à la
 * durée totale du segment, sans double comptage.
 */
final class WorkedTimeCalculator
{
    /** Un segment strictement supérieur à 12 heures est signalé. */
    public const WARNING_THRESHOLD_MINUTES = 720;

    /**
     * @return array{
     *     total_minutes: int,
     *     daily_minutes: array<string, int>,
     *     warnings: array<int, array{type: string, minutes: int, threshold_minutes: int}>
     * }
     */
    public function evaluate(CarbonInterface $start, CarbonInterface $end): array
    {
        $total = $this->minutesBetween($start, $end);
        $daily = $this->dailyMinutes($start, $end);

        if (array_sum($daily) !== $total) {
            throw new LogicException('La répartition journalière ne correspond pas à la durée totale du segment.');
        }

        return [
            'total_minutes' => $total,
            'daily_minutes' => $daily,
            'warnings' => $this->warningsForDuration($total),
        ];
    }

    public function minutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        $startUtc = CarbonImmutable::instance($start)->utc();
        $endUtc = CarbonImmutable::instance($end)->utc();

        $this->assertMinutePrecision($startUtc, $endUtc);

        $seconds = $endUtc->getTimestamp() - $startUtc->getTimestamp();

        if ($seconds <= 0) {
            throw new RuntimeException('La fin d’un segment doit être strictement postérieure à son début.');
        }

        if ($seconds % 60 !== 0) {
            throw new RuntimeException('La durée d’un segment doit être exprimée en minutes entières.');
        }

        return intdiv($seconds, 60);
    }

    /**
     * Minutes travaillées par date civile locale française.
     *
     * Les jours sans minute sont omis. Un segment qui se termine exactement à
     * minuit ne crée pas de ligne à zéro sur la date suivante.
     *
     * @return array<string, int>
     */
    public function dailyMinutes(CarbonInterface $start, CarbonInterface $end): array
    {
        $startLocal = CarbonImmutable::instance($start)->setTimezone(BusinessTime::TIMEZONE);
        $endLocal = CarbonImmutable::instance($end)->setTimezone(BusinessTime::TIMEZONE);

        $this->assertMinutePrecision($startLocal, $endLocal);

        if ($endLocal->lessThanOrEqualTo($startLocal)) {
            throw new RuntimeException('La fin d’un segment doit être strictement postérieure à son début.');
        }

        $cursor = $startLocal->startOfDay();
        $lastDay = $endLocal->startOfDay();
        $daily = [];

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $next = $cursor->addDay();
            $from = $startLocal->greaterThan($cursor) ? $startLocal : $cursor;
            $to = $endLocal->lessThan($next) ? $endLocal : $next;

            if ($to->greaterThan($from)) {
                $minutes = intdiv($to->getTimestamp() - $from->getTimestamp(), 60);

                if ($minutes > 0) {
                    $daily[$cursor->format('Y-m-d')] = $minutes;
                }
            }

            $cursor = $next;
        }

        return $daily;
    }

    /**
     * @return array<int, array{type: string, minutes: int, threshold_minutes: int}>
     */
    public function warningsForDuration(int $minutes): array
    {
        if ($minutes <= self::WARNING_THRESHOLD_MINUTES) {
            return [];
        }

        return [[
            'type' => 'segment_over_12_hours',
            'minutes' => $minutes,
            'threshold_minutes' => self::WARNING_THRESHOLD_MINUTES,
        ]];
    }

    private function assertMinutePrecision(CarbonInterface $start, CarbonInterface $end): void
    {
        if ($start->second !== 0 || $end->second !== 0
            || $start->micro !== 0 || $end->micro !== 0) {
            throw new RuntimeException('Un segment est saisi à la minute, sans seconde ni fraction.');
        }
    }
}
