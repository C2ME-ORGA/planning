<?php

namespace App\Services\Hours;

use App\Enums\TimeCategory;
use App\Exceptions\BusinessRuleException;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\WorkedTimeCalculator;
use App\Support\TimeInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Intervalles fusionnés d'une catégorie de segment.
 *
 * La fusion est effectuée séparément pour chaque catégorie : deux catégories
 * différentes ne sont jamais additionnées ni fusionnées entre elles. Les
 * durées restent calculées par `WorkedTimeCalculator`, qui porte les règles de
 * fuseau, de minuit et de changement d'heure.
 */
final class CategoryIntervals
{
    public function __construct(
        private readonly WorkedTimeCalculator $calculator,
        private readonly IntervalMerger $merger,
    ) {}

    /**
     * @param  iterable<TimesheetSegment>  $segments
     * @return array{
     *     intervals: array<int, TimeInterval>,
     *     total_minutes: int,
     *     daily_minutes: array<string, int>,
     *     overlaps: array<int, array{
     *         segment_ids: array<int, int>,
     *         start_at: CarbonImmutable,
     *         end_at: CarbonImmutable,
     *         minutes: int
     *     }>
     * }
     */
    public function build(
        TimeCategory $category,
        iterable $segments,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        if (($from === null) !== ($to === null)) {
            throw new BusinessRuleException('La fenêtre de calcul doit être complète.');
        }

        $intervals = [];

        foreach ($segments as $segment) {
            if ($segment->category !== $category) {
                continue;
            }

            $start = $segment->start_at;
            $end = $segment->end_at;

            if ($start === null || $end === null) {
                continue;
            }

            $segmentId = is_null($segment->getKey()) ? null : (int) $segment->getKey();
            $rawTimesheetId = $segment->getAttribute('timesheet_id');
            $timesheetId = $rawTimesheetId === null ? null : (int) $rawTimesheetId;
            $interval = TimeInterval::between(
                $start,
                $end,
                $segmentId,
                $timesheetId,
                $segment->category,
            );

            if ($from !== null && $to !== null) {
                $interval = $interval->clip($from, $to);

                if (! $interval instanceof TimeInterval) {
                    continue;
                }
            }

            $intervals[] = $interval;
        }

        $merged = $this->merger->merge($intervals);
        $totalMinutes = 0;
        $dailyMinutes = [];

        foreach ($merged['intervals'] as $interval) {
            $evaluation = $this->calculator->evaluate($interval->start(), $interval->end());
            $totalMinutes += $evaluation['total_minutes'];

            foreach ($evaluation['daily_minutes'] as $date => $minutes) {
                $dailyMinutes[$date] = ($dailyMinutes[$date] ?? 0) + $minutes;
            }
        }

        ksort($dailyMinutes);

        return [
            'intervals' => $merged['intervals'],
            'total_minutes' => $totalMinutes,
            'daily_minutes' => $dailyMinutes,
            'overlaps' => $merged['overlaps'],
        ];
    }
}
