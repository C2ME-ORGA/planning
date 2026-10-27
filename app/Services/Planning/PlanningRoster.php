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
        $day = $date->toDateString();

        return Employee::query()
            ->whereHas('teamAssignments', function (Builder $query) use ($team, $day): void {
                $query->where('team_id', $team->getKey())
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
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
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
