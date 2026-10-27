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

    public function transition(User $actor, Site $site): bool
    {
        return $actor->hasPermission(Permission::SitesManage)
            && ! $site->isArchived()
            && ($site->statusEnum()?->allowedTransitions() ?? []) !== [];
    }

    public function reopen(User $actor, Site $site): bool
    {
        return $actor->hasPermission(Permission::SitesReopen)
            && $actor->hasPermission(Permission::SitesManage)
            && ! $site->isArchived()
            && ($site->statusEnum()?->isTerminal() ?? false);
    }

    public function delete(User $actor, Site $site): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Site $site): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
