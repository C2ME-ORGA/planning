<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\EmployeeExitBlockedException;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sortie d'un salarié.
 *
 * La sortie clôt la période d'emploi ouverte à la date demandée. Elle est
 * refusée si une affectation ou une responsabilité resterait active au-delà de
 * cette date. Aucune affectation n'est tronquée automatiquement : la correction
 * doit être explicite, puis la sortie est revalidée sous verrou.
 */
class EmployeeLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmploymentPeriodService $periods,
        private readonly TeamLeadershipService $leaderships,
        private readonly EmployeeMutationLock $lock,
    ) {}

    public function exit(Employee $employee, string $exitDate, User $actor): EmploymentPeriod
    {
        $this->assertCanManage($actor);

        $exit = DatePeriod::parse($exitDate);

        return $this->lock->run($employee, function () use ($employee, $exit, $exitDate, $actor): EmploymentPeriod {
            return DB::transaction(function () use ($employee, $exit, $exitDate, $actor): EmploymentPeriod {
                /** @var Employee $employee */
                $employee = Employee::query()->whereKey($employee->getKey())->firstOrFail();
                $period = $this->periods->openPeriod($employee);

                if (! $period instanceof EmploymentPeriod) {
                    throw new RuntimeException('Ce salarié ne possède aucune période d’emploi ouverte.');
                }

                if ($exit->lt($period->start_date)) {
                    throw new RuntimeException('La date de sortie ne peut pas précéder le début de la période d’emploi.');
                }

                $blockers = $this->leaderships->exitBlockers($employee, $exitDate);

                if ($blockers !== []) {
                    throw new EmployeeExitBlockedException($blockers);
                }

                $period->forceFill(['end_date' => $exit->toDateString()])->save();

                $this->audit->record(
                    AuditAction::EmployeeExited,
                    $employee,
                    [
                        'employment_period_id' => $period->getKey(),
                        'start_date' => $period->start_date->toDateString(),
                        'end_date' => $exit->toDateString(),
                    ],
                    null,
                    $actor,
                );

                return $period;
            });
        });
    }

    /**
     * @return array<int, string>
     */
    public function exitBlockers(Employee $employee, string $exitDate): array
    {
        return $this->leaderships->exitBlockers($employee, $exitDate);
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::EmployeesManage)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à enregistrer une sortie.');
        }
    }
}
