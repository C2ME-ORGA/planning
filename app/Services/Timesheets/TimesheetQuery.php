<?php

namespace App\Services\Timesheets;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Lecture des relevés autorisés pour un acteur.
 *
 * La portée reprend les règles de la policy : le Bureau/Direction consulte
 * tous les relevés, un responsable consulte uniquement le périmètre historique
 * des équipes qu'il dirige, un salarié consulte ses propres relevés. Aucune
 * liste d'interface ne doit interroger `Timesheet` sans passer par cette
 * portée : le filtrage est appliqué en SQL, avant la pagination.
 */
final class TimesheetQuery
{
    /**
     * @return Builder<Timesheet>
     */
    public function visibleTo(User $actor): Builder
    {
        Gate::forUser($actor)->authorize('viewAny', Timesheet::class);

        $query = Timesheet::query();

        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return $query;
        }

        if ($actor->hasPermission(Permission::TimesheetControl)) {
            return $this->constrainToPerimeter($query, $actor);
        }

        if ($actor->hasPermission(Permission::TimesheetOwn)) {
            $employeeId = $this->employeeIdFor($actor);

            return $query->where('employee_id', $employeeId ?? 0);
        }

        return $query->whereRaw('0 = 1');
    }

    /**
     * Restreint une requête au périmètre historique d'un responsable.
     *
     * Une affectation d'équipe (principale ou temporaire, non annulée) et une
     * responsabilité d'équipe (non annulée) doivent toutes deux chevaucher la
     * semaine du relevé. Les dates sont inclusives et une date de fin nulle
     * signifie « ouverte ».
     *
     * @param  Builder<Timesheet>  $query
     * @return Builder<Timesheet>
     */
    public function constrainToPerimeter(Builder $query, User $actor): Builder
    {
        $leaderEmployeeId = $this->employeeIdFor($actor);

        if ($leaderEmployeeId === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereExists(function ($sub) use ($leaderEmployeeId): void {
            $sub->selectRaw('1')
                ->from('team_assignments as ta')
                ->join('team_leaderships as tl', 'tl.team_id', '=', 'ta.team_id')
                ->whereColumn('ta.employee_id', 'timesheets.employee_id')
                ->whereNull('ta.cancelled_at')
                ->whereNull('tl.cancelled_at')
                ->where('tl.employee_id', $leaderEmployeeId)
                ->whereColumn('ta.start_date', '<=', 'timesheets.period_end')
                ->where(function ($dates): void {
                    $dates->whereNull('ta.end_date')
                        ->orWhereColumn('ta.end_date', '>=', 'timesheets.period_start');
                })
                ->whereColumn('tl.start_date', '<=', 'timesheets.period_end')
                ->where(function ($dates): void {
                    $dates->whereNull('tl.end_date')
                        ->orWhereColumn('tl.end_date', '>=', 'timesheets.period_start');
                })
                // Chevauchement mutuel de l'affectation et de la responsabilité :
                // mêmes bornes inclusives et mêmes dates de fin nulles que
                // TimesheetPerimeter::periodsOverlap().
                ->where(function ($dates): void {
                    $dates->whereNull('tl.end_date')
                        ->orWhereColumn('ta.start_date', '<=', 'tl.end_date');
                })
                ->where(function ($dates): void {
                    $dates->whereNull('ta.end_date')
                        ->orWhereColumn('ta.end_date', '>=', 'tl.start_date');
                });
        });
    }

    public function employeeIdFor(User $actor): ?int
    {
        $employeeId = Employee::query()
            ->where('user_id', $actor->getKey())
            ->value('id');

        return $employeeId === null ? null : (int) $employeeId;
    }

    /**
     * Salariés proposés dans les filtres, sans élargir le périmètre autorisé.
     *
     * @return Collection<int, Employee>
     */
    public function employeeOptions(User $actor): Collection
    {
        $query = $this->visibleTo($actor);

        $employeeIds = $query
            ->reorder()
            ->select('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }
}
