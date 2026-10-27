<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PlanningAssignment;
use App\Models\User;

/**
 * Le planning est modifiable par le Bureau/Direction uniquement. Les rôles
 * opérationnels disposant de `planning.propose` peuvent consulter, sans écrire.
 */
class PlanningAssignmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canRead($actor);
    }

    public function view(User $actor, PlanningAssignment $assignment): bool
    {
        return $this->canRead($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::PlanningManage);
    }

    public function update(User $actor, PlanningAssignment $assignment): bool
    {
        return $actor->hasPermission(Permission::PlanningManage);
    }

    public function delete(User $actor, PlanningAssignment $assignment): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, PlanningAssignment $assignment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    private function canRead(User $actor): bool
    {
        return $actor->hasPermission(Permission::PlanningManage)
            || $actor->hasPermission(Permission::PlanningPropose);
    }
}
