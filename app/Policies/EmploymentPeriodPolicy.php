<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\EmploymentPeriod;
use App\Models\User;

class EmploymentPeriodPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::EmployeesView);
    }

    public function view(User $actor, EmploymentPeriod $period): bool
    {
        return $actor->hasPermission(Permission::EmployeesView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::EmployeesManage);
    }

    public function update(User $actor, EmploymentPeriod $period): bool
    {
        return false;
    }

    public function delete(User $actor, EmploymentPeriod $period): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
