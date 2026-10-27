<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $actor): bool { return $actor->hasPermission(Permission::ClientsView); }
    public function view(User $actor, Client $client): bool { return $actor->hasPermission(Permission::ClientsView); }
    public function create(User $actor): bool { return $actor->hasPermission(Permission::ClientsManage); }
    public function update(User $actor, Client $client): bool { return $actor->hasPermission(Permission::ClientsManage); }
    public function delete(User $actor, Client $client): bool { return false; }
    public function deleteAny(User $actor): bool { return false; }
}
