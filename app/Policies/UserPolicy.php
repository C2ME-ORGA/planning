<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Politique des comptes.
 *
 * L'Administrateur technique ne peut ni consulter, ni créer, ni modifier un
 * compte : `accounts.*` n'est accordé qu'au Bureau / Direction. Un compte ne
 * peut jamais modifier son propre rôle ni se désactiver lui-même.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::AccountsView);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::AccountsView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::AccountsCreate)
            && $actor->hasPermission(Permission::RolesAssign);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::AccountsUpdate)
            && ! $actor->is($target);
    }

    public function delete(User $actor, User $target): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::AccountsDeactivate)
            && ! $actor->is($target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::AccountsReactivate)
            && ! $actor->is($target);
    }

    public function resendActivation(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::AccountsResendActivation)
            && $target->isActive();
    }

    public function assignRole(User $actor, User $target): bool
    {
        return $actor->hasPermission(Permission::RolesAssign)
            && ! $actor->is($target);
    }
}
