<?php

namespace App\Services\Planning;

use App\Enums\PlanningHalfDay;
use App\Models\Employee;
use App\Models\PlanningAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Vue de consultation d'une journée de planning.
 *
 * Regroupe les affectations par demi-journée, calcule les chevauchements en
 * lot et expose l'historique autorisé de chaque ligne. Les relations et les
 * membres d'équipe sont chargés en une fois : aucune requête ne dépend du
 * nombre d'affectations de la journée.
 */
class PlanningDayBoard
{
    public function __construct(
        private readonly PlanningAssignmentService $assignments,
        private readonly PlanningAssignmentHistory $history,
        private readonly PlanningRoster $roster,
    ) {}

    /**
     * @return array{
     *     date: CarbonImmutable,
     *     morning: array<int, array{
     *         assignment: PlanningAssignment,
     *         members_count: int,
     *         excluded_count: int,
     *         archived: bool,
     *         warnings: array<int, array<string, mixed>>,
     *         history: array<int, array<string, mixed>>
     *     }>,
     *     afternoon: array<int, array{
     *         assignment: PlanningAssignment,
     *         members_count: int,
     *         excluded_count: int,
     *         archived: bool,
     *         warnings: array<int, array<string, mixed>>,
     *         history: array<int, array<string, mixed>>
     *     }>,
     *     team_warnings: int,
     *     employee_warnings: int
     * }
     */
    public function forDate(CarbonImmutable $date, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', PlanningAssignment::class);

        $assignments = PlanningAssignment::query()
            ->where('work_date', $date->toDateString())
            ->with(['site', 'team', 'exclusions'])
            ->orderBy('half_day')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [
                'date' => $date,
                'morning' => [],
                'afternoon' => [],
                'team_warnings' => 0,
                'employee_warnings' => 0,
            ];
        }

        $warnings = $this->assignments->warningsForAssignments($assignments);
        $timelines = $this->history->timelinesFor($assignments, $actor);
        $membersByTeam = $this->roster->membersByTeam(
            $assignments->pluck('team_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            $date,
        );

        $entries = $this->entries($assignments, $warnings, $timelines, $membersByTeam);

        return [
            'date' => $date,
            'morning' => $entries->filter(
                static fn (array $entry): bool => $entry['assignment']->half_day === PlanningHalfDay::Morning,
            )->values()->all(),
            'afternoon' => $entries->filter(
                static fn (array $entry): bool => $entry['assignment']->half_day === PlanningHalfDay::Afternoon,
            )->values()->all(),
            'team_warnings' => $this->countWarnings($entries, 'team'),
            'employee_warnings' => $this->countWarnings($entries, 'employee'),
        ];
    }

    /**
     * @param  Collection<int, PlanningAssignment>  $assignments
     * @param  array<int, array<int, array<string, mixed>>>  $warnings
     * @param  array<int, array<int, array<string, mixed>>>  $timelines
     * @param  array<int, Collection<int, Employee>>  $membersByTeam
     * @return Collection<int, array{
     *     assignment: PlanningAssignment,
     *     members_count: int,
     *     excluded_count: int,
     *     archived: bool,
     *     warnings: array<int, array<string, mixed>>,
     *     history: array<int, array<string, mixed>>
     * }>
     */
    private function entries(
        Collection $assignments,
        array $warnings,
        array $timelines,
        array $membersByTeam,
    ): Collection {
        return $assignments->map(function (PlanningAssignment $assignment) use ($warnings, $timelines, $membersByTeam): array {
            $excluded = $assignment->exclusions
                ->pluck('employee_id')
                ->map(static fn (mixed $id): int => (int) $id);

            $members = $membersByTeam[(int) $assignment->team_id] ?? collect();

            return [
                'assignment' => $assignment,
                'members_count' => $members->count() - $members->filter(
                    static fn (Employee $employee): bool => $excluded->contains((int) $employee->getKey()),
                )->count(),
                'excluded_count' => $excluded->count(),
                'archived' => (bool) $assignment->site?->isArchived(),
                'warnings' => $warnings[(int) $assignment->getKey()] ?? [],
                'history' => $timelines[(int) $assignment->getKey()] ?? [],
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array{
     *     assignment: PlanningAssignment,
     *     members_count: int,
     *     excluded_count: int,
     *     archived: bool,
     *     warnings: array<int, array<string, mixed>>,
     *     history: array<int, array<string, mixed>>
     * }>  $entries
     */
    private function countWarnings(Collection $entries, string $type): int
    {
        return $entries->sum(
            static fn (array $entry): int => collect($entry['warnings'])
                ->where('type', $type)
                ->count(),
        );
    }
}
