<?php

namespace App\Console\Commands;

use App\Support\DemoData\DemoDatasetEnvironment;
use Database\Seeders\DemoClientsAndSitesSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Alimente une base locale avec les clients et chantiers fictifs de revue.
 *
 * La commande affiche toujours la base ciblée, refuse tout environnement ou
 * hôte non autorisé et n'écrit qu'avec l'option --confirm. Elle ne crée aucun
 * compte, ne modifie aucune permission, ne contourne pas la 2FA et ne produit
 * aucun événement d'audit métier.
 */
class SeedDemoClientsAndSitesCommand extends Command
{
    protected $signature = 'planning:demo:clients-sites
        {--confirm : Confirme l’écriture des données de démonstration}';

    protected $description = 'Crée les clients et chantiers fictifs de revue sur une base locale explicitement autorisée.';

    public function handle(DemoDatasetEnvironment $environment): int
    {
        $state = $environment->inspect();

        $this->line('Base ciblée : '.$environment->describe());
        $this->line(sprintf(
            'Données prévues : %d clients et %d chantiers fictifs marqués « %s ».',
            DemoClientsAndSitesSeeder::clientCount(),
            DemoClientsAndSitesSeeder::siteCount(),
            DemoClientsAndSitesSeeder::MARKER,
        ));

        if (! $state['safe']) {
            $this->newLine();
            $this->error('Exécution refusée : '.implode(' ; ', $state['reasons']).'.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->warn('Aucune écriture effectuée. Relancez avec --confirm après avoir vérifié la base affichée.');

            return self::FAILURE;
        }

        $seeder = $this->laravel->make(DemoClientsAndSitesSeeder::class);
        $seeder->setContainer($this->laravel)->setCommand($this);

        try {
            $seeder->run();
        } catch (Throwable $exception) {
            $this->error('Jeu de démonstration interrompu, aucune écriture conservée : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Jeu de démonstration prêt.');
        $this->table(
            ['Élément', 'Créés', 'Déjà présents'],
            [
                ['Clients', $seeder->clientsCreated, $seeder->clientsExisting],
                ['Chantiers', $seeder->sitesCreated, $seeder->sitesExisting],
            ],
        );
        $this->line('Aucun compte, mot de passe ni événement d’audit n’a été créé par cette commande.');
        $this->line('Ouvrez /admin/clients et /admin/sites avec un compte Bureau / Direction.');

        return self::SUCCESS;
    }
}
