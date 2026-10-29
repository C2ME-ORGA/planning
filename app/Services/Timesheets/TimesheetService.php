<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ouverture d'un relevé hebdomadaire.
 *
 * La création est autorisée par la capacité `createFor` de la policy, avec un
 * brouillon non enregistré portant le salarié cible : la capacité globale
 * `create` ne porte aucune cible et ne doit jamais recevoir un `Employee`.
 *
 * Un relevé déjà existant pour le salarié et la semaine n'est retourné que si
 * l'acteur est autorisé à le consulter ; sinon l'opération est refusée sans
 * révéler son existence, son statut ni son contenu. La période est toujours
 * calculée par `Timesheet::weekFor()`, jamais fournie par le client.
 *
 * Un salarié sans compte lié peut recevoir un brouillon ; il ne pourra pas le
 * transmettre lui-même.
 */
class TimesheetService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function openFor(Employee $employee, string|CarbonInterface $week, User $actor): Timesheet
    {
        $period = Timesheet::weekFor($week);
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();

        $draft = new Timesheet([
            'employee_id' => $employee->getKey(),
            'status' => TimesheetStatus::Brouillon,
        ]);

        Gate::forUser($actor)->authorize('createFor', $draft);

        $existing = $this->findExisting($employee, $periodStart);

        if ($existing instanceof Timesheet) {
            Gate::forUser($actor)->authorize('view', $existing);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($employee, $periodStart, $periodEnd, $actor): Timesheet {
                $timesheet = Timesheet::query()->create([
                    'employee_id' => $employee->getKey(),
                    'status' => TimesheetStatus::Brouillon,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);

                $this->audit->record(
                    AuditAction::TimesheetCreated,
                    $timesheet,
                    [
                        'employee_id' => (int) $employee->getKey(),
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'status' => TimesheetStatus::Brouillon->value,
                    ],
                    null,
                    $actor,
                );

                return $timesheet;
            });
        } catch (QueryException $exception) {
            // Une création concurrente a pu gagner la contrainte d'unicité
            // (employee_id, period_start) : recharger puis réappliquer le
            // contrôle d'autorisation avant tout retour. Toute autre erreur SQL
            // est propagée telle quelle.
            if (! $this->isDuplicateWeekViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findExisting($employee, $periodStart);

            if (! $existing instanceof Timesheet) {
                throw $exception;
            }

            Gate::forUser($actor)->authorize('view', $existing);

            return $existing;
        }
    }

    private function findExisting(Employee $employee, string $periodStart): ?Timesheet
    {
        return Timesheet::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_start', $periodStart)
            ->first();
    }

    private function isDuplicateWeekViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
