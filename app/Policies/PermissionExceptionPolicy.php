<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PermissionException;
use App\Models\User;

class PermissionExceptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::PermissionExceptionsManage);
    }

    public function view(User $actor, PermissionException $exception): bool
    {
        return $actor->hasPermission(Permission::PermissionExceptionsManage);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::PermissionExceptionsManage);
    }

    public function revoke(User $actor, PermissionException $exception): bool
    {
        return $actor->hasPermission(Permission::PermissionExceptionsManage);
    }

    public function update(User $actor, PermissionException $exception): bool
    {
        return false;
    }

    public function delete(User $actor, PermissionException $exception): bool
    {
        return false;
    }
}
