<?php

namespace App\Services\Planning;

use App\Models\Employee;
use App\Models\Team;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Composition d'une équipe à une date donnée.
 *
 * Les membres sont déduits des affectations d'équipe historisées
 * (`team_assignments`), principales ou temporaires, non annulées et couvrant la
 * date. Aucune copie de la composition n'est stockée dans le planning : une
 * affectation temporaire reste donc indépendante de l'équipe principale.
 */
class PlanningRoster
{
    /**
     * @return Collection<int, Employee>
     */
    public function members(Team $team, CarbonImmutable $date): Collection
    {
        $teamId = (int) $team->getKey();

        return $this->membersByTeam([$teamId], $date)[$teamId] ?? collect();
    }

    /**
     * Charge en une fois les membres de plusieurs équipes à une même date.
     *
     * Les affectations d'équipe et les périodes d'emploi sont filtrées par les
     * mêmes règles temporelles que `members()`. Le regroupement par équipe est
     * fait en mémoire : le nombre de requêtes ne dépend pas du nombre d'équipes.
     *
     * @param  array<int, int>  $teamIds
     * @return array<int, Collection<int, Employee>>
     */
    public function membersByTeam(array $teamIds, CarbonImmutable $date): array
    {
        $teamIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $teamIds,
        )));

        if ($teamIds === []) {
            return [];
        }

        $day = $date->toDateString();
        $grouped = array_fill_keys($teamIds, []);

        $employees = Employee::query()
            ->whereHas('teamAssignments', function (Builder $query) use ($teamIds, $day): void {
                $query->whereIn('team_id', $teamIds)
                    ->whereNull('cancelled_at')
                    ->where('start_date', '<=', $day)
                    ->where(static function (Builder $query) use ($day): void {
                        $query->whereNull('end_date')->orWhere('end_date', '>=', $day);
                    });
            })
            ->whereHas('employmentPeriods', function (Builder $query) use ($day): void {
                $query->where('start_date', '<=', $day)
                    ->where(static function (Builder $query) use ($day): void {
                        $query->whereNull('end_date')->orWhere('end_date', '>=', $day);
                    });
            })
            ->with(['teamAssignments' => function ($query) use ($teamIds, $day): void {
                $query->whereIn('team_id', $teamIds)
                    ->whereNull('cancelled_at')
                    ->where('start_date', '<=', $day)
                    ->where(static function (Builder $query) use ($day): void {
                        $query->whereNull('end_date')->orWhere('end_date', '>=', $day);
                    });
            }])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        foreach ($employees as $employee) {
            foreach ($employee->teamAssignments as $assignment) {
                $teamId = (int) $assignment->team_id;

                if (array_key_exists($teamId, $grouped)) {
                    $grouped[$teamId][(int) $employee->getKey()] = $employee;
                }
            }
        }

        $membersByTeam = [];

        foreach ($grouped as $teamId => $teamEmployees) {
            $membersByTeam[(int) $teamId] = collect($teamEmployees)->values();
        }

        return $membersByTeam;
    }

    /**
     * Options de sélection pour Filament. Les salariés déjà exclus sont
     * conservés même s'ils ne font plus partie de l'équipe, afin de ne pas
     * perdre silencieusement une exclusion existante lors d'une modification.
     *
     * @param  array<int, int>  $includeEmployeeIds
     * @return array<int, string>
     */
    public function options(?int $teamId, ?string $date, array $includeEmployeeIds = []): array
    {
        $roster = collect();

        if ($teamId !== null && $date !== null && trim($date) !== '') {
            $team = Team::query()->find($teamId);

            if ($team instanceof Team) {
                try {
                    $roster = $this->members($team, DatePeriod::parse($date));
                } catch (Throwable) {
                    $roster = collect();
                }
            }
        }

        $included = [];

        if ($includeEmployeeIds !== []) {
            $included = Employee::query()
                ->whereIn('id', array_values(array_unique($includeEmployeeIds)))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->mapWithKeys(static fn (Employee $employee): array => [
                    (int) $employee->getKey() => $employee->fullName(),
                ])
                ->all();
        }

        return collect($included)
            ->union(
                $roster->mapWithKeys(static fn (Employee $employee): array => [
                    (int) $employee->getKey() => $employee->fullName(),
                ])->all(),
            )
            ->all();
    }
}
