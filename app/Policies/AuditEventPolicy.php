<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\User;

/**
 * Le journal d'audit est strictement en lecture seule.
 */
class AuditEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::AuditView);
    }

    public function view(User $actor, AuditEvent $event): bool
    {
        return $actor->hasPermission(Permission::AuditView);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $actor, AuditEvent $event): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
