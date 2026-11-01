<?php

namespace App\Services\Timesheets;

use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;

/**
 * Projection de lecture d'un relevé pour les interfaces.
 *
 * Les durées restent calculées par `WorkedTimeCalculator` : cette classe ne
 * réimplémente aucune règle horaire. Elle regroupe seulement les segments par
 * date civile locale et met en forme des minutes entières.
 */
final class TimesheetSummary
{
    public function __construct(
        private readonly WorkedTimeCalculator $calculator,
    ) {}

    public function totalMinutes(Timesheet $timesheet): int
    {
        $total = 0;

        foreach ($this->segments($timesheet) as $segment) {
            $total += $this->segmentMinutes($segment);
        }

        return $total;
    }

    public function segmentMinutes(TimesheetSegment $segment): int
    {
        $start = $segment->start_at;
        $end = $segment->end_at;

        if ($start === null || $end === null) {
            return 0;
        }

        return $this->calculator->minutesBetween($start, $end);
    }

    /**
     * Minutes par date civile locale.
     *
     * @return array<string, int>
     */
    public function minutesByDay(Timesheet $timesheet): array
    {
        $minutes = [];

        foreach ($this->segments($timesheet) as $segment) {
            $start = $segment->start_at;
            $end = $segment->end_at;

            if ($start === null || $end === null) {
                continue;
            }

            foreach ($this->calculator->dailyMinutes($start, $end) as $date => $dayMinutes) {
                $minutes[$date] = ($minutes[$date] ?? 0) + $dayMinutes;
            }
        }

        ksort($minutes);

        return $minutes;
    }

    /**
     * Segments regroupés par date civile locale de début.
     *
     * Un segment qui traverse minuit reste rattaché à sa date de début ; ses
     * bornes complètes sont affichées pour ne jamais masquer la nuit.
     *
     * @return array<string, Collection<int, TimesheetSegment>>
     */
    public function segmentsByDay(Timesheet $timesheet): array
    {
        $days = [];

        foreach ($this->segments($timesheet) as $segment) {
            $start = $segment->start_at;

            if ($start === null) {
                continue;
            }

            $date = BusinessTime::toLocal($start)->format('Y-m-d');
            $days[$date] ??= new Collection;
            $days[$date]->push($segment);
        }

        ksort($days);

        return $days;
    }

    public function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining === 0
            ? sprintf('%d h', $hours)
            : sprintf('%d h %02d', $hours, $remaining);
    }

    /**
     * @return Collection<int, TimesheetSegment>
     */
    private function segments(Timesheet $timesheet): Collection
    {
        if ($timesheet->relationLoaded('segments')) {
            /** @var Collection<int, TimesheetSegment> $segments */
            $segments = $timesheet->getRelation('segments');

            return $segments;
        }

        return $timesheet->segments()->get();
    }
}
