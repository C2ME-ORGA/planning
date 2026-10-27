<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::SitesView);
    }

    public function view(User $actor, Site $site): bool
    {
        return $actor->hasPermission(Permission::SitesView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::SitesManage);
    }

    public function update(User $actor, Site $site): bool
    {
        return $actor->hasPermission(Permission::SitesManage) && ! $site->isArchived();
    }

    public function archive(User $actor, Site $site): bool
    {
        return $actor->hasPermission(Permission::SitesManage) && ! $site->isArchived();
    }

    public function delete(User $actor, Site $site): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
