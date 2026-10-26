<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fiche salarié : identité minimale et lien facultatif vers un compte.
 *
 * Aucune donnée RH sensible n'est stockée dans ce lot. La fiche ne peut pas
 * être supprimée ; la désactivation d'un compte ne la supprime jamais.
 */
class EmployeeService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeMutationLock $lock,
        private readonly TeamLeadershipService $leaderships,
    ) {}

    public function create(string $firstName, string $lastName, User $actor): Employee
    {
        $this->assertCanManage($actor);

        [$firstName, $lastName] = $this->names($firstName, $lastName);

        return DB::transaction(function () use ($firstName, $lastName, $actor): Employee {
            $employee = Employee::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            $this->audit->record(
                AuditAction::EmployeeCreated,
                $employee,
                ['employee_id' => $employee->getKey()],
                null,
                $actor,
            );

            return $employee;
        });
    }

    public function update(Employee $employee, string $firstName, string $lastName, User $actor): Employee
    {
        $this->assertCanManage($actor);

        [$firstName, $lastName] = $this->names($firstName, $lastName);

        return $this->lock->run($employee, function () use ($employee, $firstName, $lastName, $actor): Employee {
            return DB::transaction(function () use ($employee, $firstName, $lastName, $actor): Employee {
                $employee = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                $changed = [];

                if ($employee->first_name !== $firstName) {
                    $changed[] = 'first_name';
                }

                if ($employee->last_name !== $lastName) {
                    $changed[] = 'last_name';
                }

                if ($changed === []) {
                    return $employee;
                }

                $employee->forceFill([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ])->save();

                $this->audit->record(
                    AuditAction::EmployeeUpdated,
                    $employee,
                    ['changed' => $changed],
                    null,
                    $actor,
                );

                return $employee;
            });
        });
    }

    /**
     * Lie un compte applicatif non déjà rattaché. Le lien est facultatif et
     * unique : un compte ne peut appartenir qu'à un seul salarié.
     */
    public function linkAccount(Employee $employee, User $user, User $actor): Employee
    {
        $this->assertCanManage($actor);

        return $this->lock->run($employee, function () use ($employee, $user, $actor): Employee {
            return DB::transaction(function () use ($employee, $user, $actor): Employee {
                $employee = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                if ($employee->user_id !== null) {
                    throw new RuntimeException('Ce salarié possède déjà un compte lié. Déliez-le d’abord.');
                }

                // Évite qu'un même compte soit lié à deux salariés par deux
                // requêtes concurrentes ; l'index unique reste le filet final.
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                if (Employee::query()->where('user_id', $user->getKey())->exists()) {
                    throw new RuntimeException('Ce compte est déjà lié à un autre salarié.');
                }

                $this->leaderships->assertAccountChangeAllowed($employee, $user);

                $employee->forceFill(['user_id' => $user->getKey()])->save();

                $this->audit->record(
                    AuditAction::EmployeeAccountLinked,
                    $employee,
                    ['user_id' => $user->getKey()],
                    null,
                    $actor,
                );

                return $employee;
            });
        });
    }

    public function unlinkAccount(Employee $employee, User $actor): Employee
    {
        $this->assertCanManage($actor);

        return $this->lock->run($employee, function () use ($employee, $actor): Employee {
            return DB::transaction(function () use ($employee, $actor): Employee {
                $employee = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

                if ($employee->user_id === null) {
                    throw new RuntimeException('Ce salarié ne possède aucun compte lié.');
                }

                $this->leaderships->assertAccountChangeAllowed($employee, null);

                $previousUserId = $employee->user_id;
                $employee->forceFill(['user_id' => null])->save();

                $this->audit->record(
                    AuditAction::EmployeeAccountUnlinked,
                    $employee,
                    ['previous_user_id' => $previousUserId],
                    null,
                    $actor,
                );

                return $employee;
            });
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function names(string $firstName, string $lastName): array
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($firstName === '' || $lastName === '') {
            throw new RuntimeException('Le prénom et le nom sont obligatoires.');
        }

        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new RuntimeException('Le prénom et le nom sont limités à 100 caractères.');
        }

        return [$firstName, $lastName];
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::EmployeesManage)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à gérer les salariés.');
        }
    }
}
