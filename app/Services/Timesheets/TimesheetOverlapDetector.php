<?php

namespace App\Services\Timesheets;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Détection des chevauchements de segments d'un même salarié.
 *
 * Toutes les catégories et tous les relevés du salarié sont pris en compte.
 * Le chevauchement est un avertissement : ce service ne modifie ni ne supprime
 * aucune donnée.
 *
 * Deux API distinctes empêchent l'auto-détection :
 * - `overlapsForSegment()` et `warningsForSegment()` sont réservées aux segments
 *   déjà persistés ; l'exclusion s'appuie sur l'identifiant en base ;
 * - `overlapsForCandidate()` et `warningsForCandidate()` vérifient une plage qui
 *   n'existe pas encore.
 */
final class TimesheetOverlapDetector
{
    /**
     * Autres segments du même salarié qui chevauchent un segment existant.
     * Le segment doit être persisté : son identifiant en base est utilisé pour
     * l'exclure systématiquement.
     *
     * @return Collection<int, TimesheetSegment>
     */
    public function overlapsForSegment(TimesheetSegment $segment): Collection
    {
        $segment->loadMissing('timesheet.employee');

        $timesheet = $segment->timesheet;
        $start = $segment->start_at;
        $end = $segment->end_at;

        if (! $timesheet instanceof Timesheet
            || ! $timesheet->employee instanceof Employee
            || $start === null
            || $end === null) {
            throw new RuntimeException(
                'Le segment doit être rattaché à un salarié et à des instants complets.',
            );
        }

        return $this->queryOverlaps(
            $timesheet->employee,
            $start,
            $end,
            (int) $segment->getKey(),
        );
    }

    /**
     * Segments existants qui chevauchent une plage candidate (création ou
     * recherche explicite). Aucun segment courant n'existe à exclure.
     *
     * @return Collection<int, TimesheetSegment>
     */
    public function overlapsForCandidate(
        Employee $employee,
        CarbonInterface $start,
        CarbonInterface $end,
    ): Collection {
        return $this->queryOverlaps($employee, $start, $end, null);
    }

    /**
     * Avertissements pour un segment existant. Le segment doit être persisté ;
     * il est exclu de la recherche par son identifiant.
     *
     * @return array<int, array{
     *     type: string,
     *     segment_id: int,
     *     timesheet_id: int,
     *     start_at: string,
     *     end_at: string
     * }>
     */
    public function warningsForSegment(TimesheetSegment $segment): array
    {
        return $this->project($this->overlapsForSegment($segment));
    }

    /**
     * Avertissements pour une plage candidate qui n'existe pas encore.
     *
     * @return array<int, array{
     *     type: string,
     *     segment_id: int,
     *     timesheet_id: int,
     *     start_at: string,
     *     end_at: string
     * }>
     */
    public function warningsForCandidate(
        Employee $employee,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        return $this->project($this->overlapsForCandidate($employee, $start, $end));
    }

    /**
     * @return Collection<int, TimesheetSegment>
     */
    private function queryOverlaps(
        Employee $employee,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $exceptSegmentId,
    ): Collection {
        $startUtc = CarbonImmutable::instance($start)->utc();
        $endUtc = CarbonImmutable::instance($end)->utc();

        if ($endUtc->lessThanOrEqualTo($startUtc)) {
            throw new RuntimeException('La fin d’un segment doit être strictement postérieure à son début.');
        }

        return TimesheetSegment::query()
            ->whereHas('timesheet', function (Builder $query) use ($employee): void {
                $query->where('employee_id', $employee->getKey());
            })
            ->where('start_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('end_at', '>', $startUtc->format('Y-m-d H:i:s'))
            ->when(
                $exceptSegmentId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($exceptSegmentId),
            )
            ->with('timesheet')
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Projection destinée à l'interface ou à un futur audit : aucun contenu
     * nominatif, uniquement des identifiants et des instants UTC.
     *
     * @param  Collection<int, TimesheetSegment>  $segments
     * @return array<int, array{
     *     type: string,
     *     segment_id: int,
     *     timesheet_id: int,
     *     start_at: string,
     *     end_at: string
     * }>
     */
    private function project(Collection $segments): array
    {
        return $segments
            ->map(static fn (TimesheetSegment $segment): array => [
                'type' => 'segment_overlap',
                'segment_id' => (int) $segment->getKey(),
                'timesheet_id' => (int) $segment->timesheet_id,
                'start_at' => $segment->start_at?->toIso8601String() ?? '',
                'end_at' => $segment->end_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }
}
