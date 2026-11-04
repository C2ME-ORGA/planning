<?php

namespace App\Services\Hours;

use App\Support\TimeInterval;
use Carbon\CarbonImmutable;

/**
 * Fusion d'intervalles temporels et diagnostic des chevauchements.
 *
 * La fusion produit l'union des intervalles : une minute commune n'est
 * comptée qu'une seule fois. Les chevauchements réels restent identifiables
 * dans le diagnostic ; les intervalles seulement adjacents sont fusionnés
 * sans être signalés comme anomalie.
 */
final class IntervalMerger
{
    /**
     * @param  iterable<TimeInterval>  $intervals
     * @return array{
     *     intervals: array<int, TimeInterval>,
     *     overlaps: array<int, array{
     *         segment_ids: array<int, int>,
     *         start_at: CarbonImmutable,
     *         end_at: CarbonImmutable,
     *         minutes: int
     *     }>
     * }
     */
    public function merge(iterable $intervals): array
    {
        /** @var array<int, TimeInterval> $sorted */
        $sorted = [];

        foreach ($intervals as $interval) {
            $sorted[] = $interval;
        }

        usort($sorted, static function (TimeInterval $first, TimeInterval $second): int {
            return $first->start()->getTimestamp() <=> $second->start()->getTimestamp()
                ?: $first->end()->getTimestamp() <=> $second->end()->getTimestamp();
        });

        $overlaps = $this->overlaps($sorted);
        $merged = [];
        $current = null;

        foreach ($sorted as $interval) {
            if (! $current instanceof TimeInterval) {
                $current = $interval;

                continue;
            }

            if ($interval->start()->lessThanOrEqualTo($current->end())) {
                $current = $current->mergeWith($interval);
            } else {
                $merged[] = $current;
                $current = $interval;
            }
        }

        if ($current instanceof TimeInterval) {
            $merged[] = $current;
        }

        return [
            'intervals' => $merged,
            'overlaps' => $overlaps,
        ];
    }

    /**
     * @param  array<int, TimeInterval>  $sorted
     * @return array<int, array{
     *     segment_ids: array<int, int>,
     *     timesheet_ids: array<int, int>,
     *     category: string|null,
     *     start_at: CarbonImmutable,
     *     end_at: CarbonImmutable,
     *     minutes: int
     * }>
     */
    private function overlaps(array $sorted): array
    {
        $overlaps = [];
        $count = count($sorted);

        for ($firstIndex = 0; $firstIndex < $count; $firstIndex++) {
            $first = $sorted[$firstIndex];

            for ($secondIndex = $firstIndex + 1; $secondIndex < $count; $secondIndex++) {
                $second = $sorted[$secondIndex];

                if ($second->start()->greaterThanOrEqualTo($first->end())) {
                    break;
                }

                $start = $first->start()->greaterThan($second->start())
                    ? $first->start()
                    : $second->start();
                $end = $first->end()->lessThan($second->end())
                    ? $first->end()
                    : $second->end();

                $segmentIds = [];
                $timesheetIds = [];

                foreach ([$first, $second] as $interval) {
                    if ($interval->segmentId() !== null) {
                        $segmentIds[] = $interval->segmentId();
                    }
                    if ($interval->timesheetId() !== null) {
                        $timesheetIds[] = $interval->timesheetId();
                    }
                }

                $overlaps[] = [
                    'segment_ids' => $segmentIds,
                    'timesheet_ids' => array_unique($timesheetIds),
                    'category' => $first->category()?->value,
                    'start_at' => $start,
                    'end_at' => $end,
                    'minutes' => intdiv($end->getTimestamp() - $start->getTimestamp(), 60),
                ];
            }
        }

        return $overlaps;
    }
}
