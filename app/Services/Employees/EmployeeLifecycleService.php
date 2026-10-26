<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\EmployeeExitBlockedException;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sortie d'un salarié.
 *
 * La sortie clôt la période d'emploi ouverte à la date demandée. Elle est
 * refusée si une affectation ou une responsabilité resterait active au-delà de
 * cette date. Aucune affectation n'est tronquée automatiquement : la correction
 * doit être explicite, puis la sortie est revalidée sous verrou. Si la
 * désactivation du compte est demandée, une responsabilité qui couvre encore la
 * date effective de désactivation (règle des dates inclusives) refuse
 * l'opération avant toute écriture.
 */
class EmployeeLifecycleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmploymentPeriodService $periods,
        private readonly TeamLeadershipService $leaderships,
        private readonly EmployeeMutationLock $lock,
        private readonly AccountAdministration $accounts,
    ) {}

    public function exit(
        Employee $employee,
        string $exitDate,
        User $actor,
        bool $deactivateAccount = false,
    ): EmploymentPeriod {
        $this->assertCanManage($actor);

        $exit = DatePeriod::parse($exitDate);

        if ($deactivateAccount && $exit->gt(CarbonImmutable::today())) {
            throw new RuntimeException(
                'La désactivation immédiate du compte n’est possible que pour une sortie effective aujourd’hui ou dans le passé.',
            );
        }

        return $this->lock->run($employee, function () use ($employee, $exit, $exitDate, $actor, $deactivateAccount): EmploymentPeriod {
            return DB::transaction(function () use ($employee, $exit, $exitDate, $actor, $deactivateAccount): EmploymentPeriod {
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

                $accountLinked = $employee->user_id !== null;
                $user = $employee->user;

                // Règle des dates inclusives, vérifiée avant toute écriture :
                // la désactivation est immédiate (date effective = aujourd'hui)
                // et reste refusée si une responsabilité couvre encore cette
                // date, y compris une responsabilité se terminant à la date de
                // sortie. Rien n'est encore écrit à ce stade : l'opération reste
                // atomique et la sortie sans désactivation reste possible.
                if ($deactivateAccount && $user instanceof User) {
                    $this->leaderships->assertDeactivationAllowed($user);
                }

                // Écriture Query Builder assumée et localisée ici : le modèle
                // EmploymentPeriod refuse toute mise à jour Eloquent (append-only
                // strict). La clôture n'est légitime qu'à cet endroit, après
                // permission, verrou salarié, relecture, contrôle des
                // affectations/responsabilités et sous transaction. La condition
                // whereNull('end_date') protège contre une clôture concurrente :
                // 0 ligne affectée = 0 modification = exception et rollback.
                $affected = DB::table('employment_periods')
                    ->where('id', $period->getKey())
                    ->whereNull('end_date')
                    ->update([
                        'end_date' => $exit->toDateString(),
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw new RuntimeException(
                        'La période d’emploi a déjà été clôturée par une autre opération. Aucun changement n’est enregistré.',
                    );
                }

                $period->refresh();

                $accountDeactivated = false;

                if ($deactivateAccount && $user instanceof User) {
                    $accountDeactivated = $this->accounts->deactivateForEmployeeExit(
                        $user,
                        'Désactivation demandée lors de la sortie du salarié.',
                        $actor,
                    );
                }

                $this->audit->record(
                    AuditAction::EmployeeExited,
                    $employee,
                    [
                        'employment_period_id' => $period->getKey(),
                        'start_date' => $period->start_date->toDateString(),
                        'end_date' => $exit->toDateString(),
                        'account_linked' => $accountLinked,
                        'account_deactivation_requested' => $deactivateAccount,
                        'account_deactivated' => $accountDeactivated,
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
