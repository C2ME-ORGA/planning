<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCliActor;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Services\Accounts\AccountProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Amorçage du premier compte ou création contrôlée d'un compte suppléant.
 */
class BootstrapBureauDirectionCommand extends Command
{
    use ResolvesCliActor;

    protected $signature = 'planning:bootstrap-bureau
        {email : Adresse e-mail du compte Bureau / Direction}
        {--name= : Nom affiché}
        {--additional : Crée un compte supplémentaire, autorisé par un compte Bureau / Direction existant}
        {--actor= : Adresse e-mail du compte Bureau / Direction qui autorise la création supplémentaire}
        {--confirm : Confirme la création du compte}';

    protected $description = 'Crée le premier compte Bureau / Direction ou un compte supplémentaire autorisé.';

    public function handle(AccountProvisioner $accounts): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour confirmer la création du compte.');

            return self::FAILURE;
        }

        $email = trim((string) $this->argument('email'));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nom affiché du compte')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('Le nom affiché est obligatoire.');

            return self::FAILURE;
        }

        try {
            if ($this->option('additional')) {
                $actor = $this->resolveActorWith(Permission::AccountsCreate);

                if (! $actor instanceof User || $actor->role !== Role::BureauDirection) {
                    $this->error('La création supplémentaire exige un acteur Bureau / Direction actif.');

                    return self::FAILURE;
                }

                if (! $actor->hasPermission(Permission::RolesAssign)) {
                    $this->error('Ce compte ne peut pas attribuer le rôle Bureau / Direction.');

                    return self::FAILURE;
                }

                $user = $accounts->create($name, $email, Role::BureauDirection, $actor);
            } else {
                if (trim((string) $this->option('actor')) !== '') {
                    $this->error('L’option --actor est réservée au mode --additional.');

                    return self::FAILURE;
                }

                $user = $accounts->bootstrapBureauDirection($name, $email);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Compte Bureau / Direction créé pour {$user->email}.");
        $this->line('Un lien d’activation a été envoyé. Aucun mot de passe n’est connu de l’opérateur.');

        return self::SUCCESS;
    }
}
