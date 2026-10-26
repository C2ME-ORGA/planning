<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TeamLeadership;
use App\Models\User;

class TeamLeadershipPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function view(User $actor, TeamLeadership $leadership): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function update(User $actor, TeamLeadership $leadership): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function delete(User $actor, TeamLeadership $leadership): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
