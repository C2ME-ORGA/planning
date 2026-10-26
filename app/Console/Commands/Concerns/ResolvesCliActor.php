<?php

namespace App\Console\Commands\Concerns;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Str;

trait ResolvesCliActor
{
    /**
     * Les opérations sensibles lancées en CLI doivent être autorisées par un
     * compte métier habilité. La CLI ne crée jamais de droit par elle-même.
     */
    protected function resolveActorWith(Permission $permission): ?User
    {
        $email = Str::lower(trim((string) $this->option('actor')));

        if ($email === '') {
            $this->error('Option --actor obligatoire : adresse du compte habilité qui autorise l’opération.');

            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if (! $actor instanceof User || ! $actor->isActive()) {
            $this->error('Compte auteur introuvable ou inactif.');

            return null;
        }

        if (! $actor->hasPermission($permission)) {
            $this->error('Ce compte ne dispose pas de la capacité requise.');

            return null;
        }

        return $actor;
    }
}
