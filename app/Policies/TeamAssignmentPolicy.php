<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TeamAssignment;
use App\Models\User;

class TeamAssignmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function view(User $actor, TeamAssignment $assignment): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function update(User $actor, TeamAssignment $assignment): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function delete(User $actor, TeamAssignment $assignment): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
