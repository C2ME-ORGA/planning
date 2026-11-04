<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;

/**
 * Politique des équipes. Une équipe n'est jamais supprimée : l'historique des
 * affectations et des responsabilités doit rester exploitable.
 */
class TeamPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function view(User $actor, Team $team): bool
    {
        return $actor->hasPermission(Permission::TeamsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function update(User $actor, Team $team): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function delete(User $actor, Team $team): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function manageAssignments(User $actor, Team $team): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }

    public function manageLeadership(User $actor, Team $team): bool
    {
        return $actor->hasPermission(Permission::TeamsManage);
    }
}
