<?php

namespace App\Services\Timesheets;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\TeamLeadership;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Périmètre historique de contrôle d'un relevé.
 *
 * Un chef d'équipe ou un conducteur de travaux peut contrôler un relevé si le
 * salarié a été affecté, pendant au moins une partie de la semaine, à une équipe
 * qu'il dirigeait pendant cette même période. Les affectations principales et
 * temporaires sont prises en compte, ainsi que les responsabilités terminées
 * normalement. Les annulations sont exclues : le dépôt ne les autorise que
 * avant le début de la période concernée.
 */
final class TimesheetPerimeter
{
    public function canControl(User $actor, Timesheet $timesheet): bool
    {
        if (! $actor->hasPermission(Permission::TimesheetControl)) {
            return false;
        }

        $employee = Employee::query()
            ->where('user_id', $actor->getKey())
            ->first();

        if (! $employee instanceof Employee) {
            return false;
        }

        return in_array(
            (int) $employee->getKey(),
            $this->leaderEmployeeIdsFor($timesheet),
            true,
        );
    }

    /**
     * Responsables d'équipe admissibles pour la semaine du relevé.
     *
     * Aucune priorité n'est appliquée : tous les responsables couvrant au moins
     * une partie de la semaine sont retournés.
     *
     * @return array<int, int>
     */
    public function leaderEmployeeIdsFor(Timesheet $timesheet): array
    {
        $start = $timesheet->period_start;
        $end = $timesheet->period_end;

        if ($start === null || $end === null) {
            throw new RuntimeException('Un relevé doit avoir une période complète.');
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $employeeId = (int) $timesheet->employee_id;

        $leaderships = TeamLeadership::query()
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', $endDate)
            ->where(static function (Builder $query) use ($startDate): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
            })
            ->with(['team.assignments' => function (HasMany $query) use ($employeeId, $startDate, $endDate): void {
                $query->where('employee_id', $employeeId)
                    ->whereNull('cancelled_at')
                    ->where('start_date', '<=', $endDate)
                    ->where(static function (Builder $query) use ($startDate): void {
                        $query->whereNull('end_date')->orWhere('end_date', '>=', $startDate);
                    });
            }])
            ->get();

        $leaders = [];

        foreach ($leaderships as $leadership) {
            $team = $leadership->team;

            if ($team === null) {
                continue;
            }

            foreach ($team->assignments as $assignment) {
                if (! $this->periodsOverlap(
                    $leadership->start_date,
                    $leadership->end_date,
                    $assignment->start_date,
                    $assignment->end_date,
                )) {
                    continue;
                }

                $leaders[(int) $leadership->employee_id] = (int) $leadership->employee_id;
                break;
            }
        }

        return array_values($leaders);
    }

    private function periodsOverlap(
        CarbonInterface $firstStart,
        ?CarbonInterface $firstEnd,
        CarbonInterface $secondStart,
        ?CarbonInterface $secondEnd,
    ): bool {
        return ($firstEnd === null || $secondStart->lessThanOrEqualTo($firstEnd))
            && ($secondEnd === null || $firstStart->lessThanOrEqualTo($secondEnd));
    }
}
