<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;

/**
 * Politique des fiches salariés. L'Administrateur technique ne reçoit aucun
 * droit RH ; les autres rôles non habilités sont refusés côté serveur.
 */
class EmployeePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::EmployeesView);
    }

    public function view(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function update(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function delete(User $actor, Employee $employee): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function linkAccount(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function unlinkAccount(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function addEmploymentPeriod(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function recordExit(User $actor, Employee $employee): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }
}
