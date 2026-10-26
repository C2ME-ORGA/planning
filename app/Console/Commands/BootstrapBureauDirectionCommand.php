<?php

namespace App\Console\Commands;

use App\Services\Accounts\AccountProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Initialisation contrôlée du premier compte Bureau / Direction.
 *
 * La commande est réservée à un accès serveur (CLI), exige une confirmation
 * explicite, refuse toute promotion d'un compte existant et se ferme dès
 * qu'un compte Bureau / Direction existe. Aucun rôle n'est accordé depuis
 * l'interface d'administration technique.
 */
class BootstrapBureauDirectionCommand extends Command
{
    protected $signature = 'planning:bootstrap-bureau
        {email : Adresse e-mail du premier compte Bureau / Direction}
        {--name= : Nom affiché}
        {--confirm : Confirme la création du compte}';

    protected $description = 'Crée le premier compte Bureau / Direction et envoie son lien d’activation.';

    public function handle(AccountProvisioner $accounts): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour créer le premier compte Bureau / Direction.');

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
            $user = $accounts->bootstrapBureauDirection($name, $email);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Compte Bureau / Direction créé pour {$user->email}.");
        $this->line('Un lien d’activation a été envoyé. Aucun mot de passe n’est connu de l’opérateur.');

        return self::SUCCESS;
    }
}
