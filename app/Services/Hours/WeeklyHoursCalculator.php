<?php

namespace App\Services\Hours;

use App\Enums\TimeCategory;
use App\Models\TimesheetSegment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\WeekDay;

/**
 * Calcul des heures supplémentaires hebdomadaires.
 *
 * La semaine de référence va du lundi 00:00 au dimanche 23:59, heure locale
 * française. Seul le travail effectif est compté, après fusion des
 * chevauchements. Le déficit éventuel est purement informatif : aucun report
 * n'est effectué vers une autre semaine et aucune majoration n'est calculée.
 */
final class WeeklyHoursCalculator
{
    public const WEEKLY_THRESHOLD_MINUTES = 39 * 60;

    public function __construct(
        private readonly CategoryIntervals $intervals,
    ) {}

    /**
     * @param  iterable<TimesheetSegment>  $segments
     * @return array{
     *     week_start: string,
     *     week_end: string,
     *     effective_minutes: int,
     *     threshold_minutes: int,
     *     overtime_minutes: int,
     *     deficit_minutes: int,
     *     by_day: array<string, int>,
     *     overlaps: array<int, array{
     *         segment_ids: array<int, int>,
     *         start_at: CarbonImmutable,
     *         end_at: CarbonImmutable,
     *         minutes: int
     *     }>
     * }
     */
    public function calculate(iterable $segments, CarbonInterface $week): array
    {
        $start = BusinessTime::toLocal($week)
            ->startOfDay()
            ->startOfWeek(WeekDay::Monday->value);
        $end = $start->addDays(7);

        $effective = $this->intervals->build(
            TimeCategory::TravailEffectif,
            $segments,
            $start,
            $end,
        );

        $workedMinutes = $effective['total_minutes'];
        $overtimeMinutes = max(0, $workedMinutes - self::WEEKLY_THRESHOLD_MINUTES);
        $deficitMinutes = max(0, self::WEEKLY_THRESHOLD_MINUTES - $workedMinutes);

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->subDay()->toDateString(),
            'effective_minutes' => $workedMinutes,
            'threshold_minutes' => self::WEEKLY_THRESHOLD_MINUTES,
            'overtime_minutes' => $overtimeMinutes,
            'deficit_minutes' => $deficitMinutes,
            'by_day' => $effective['daily_minutes'],
            'overlaps' => $effective['overlaps'],
        ];
    }
}
