<?php

namespace App\Console\Commands;

use App\Support\DemoData\DemoDatasetEnvironment;
use Database\Seeders\DemoEmployeesAndTeamsSeeder;
use Illuminate\Console\Command;
use Throwable;

class SeedDemoEmployeesAndTeamsCommand extends Command
{
    protected $signature = 'planning:demo:employees-teams
        {--confirm : Confirme l’écriture des données de démonstration}';

    protected $description = 'Crée les salariés, périodes et équipes fictifs du Lot 3 sur une base locale autorisée.';

    public function handle(DemoDatasetEnvironment $environment): int
    {
        $state = $environment->inspect();

        $this->line('Base ciblée : '.$environment->describe());
        $this->line(sprintf(
            'Données prévues : %d salariés et %d équipes fictifs marqués « %s ».',
            DemoEmployeesAndTeamsSeeder::employeeCount(),
            DemoEmployeesAndTeamsSeeder::teamCount(),
            DemoEmployeesAndTeamsSeeder::MARKER,
        ));

        if (! $state['safe']) {
            $this->error('Exécution refusée : '.implode(' ; ', $state['reasons']).'.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn('Aucune écriture effectuée. Relancez avec --confirm après avoir vérifié la base affichée.');

            return self::FAILURE;
        }

        $seeder = $this->laravel->make(DemoEmployeesAndTeamsSeeder::class);
        $seeder->setContainer($this->laravel)->setCommand($this);

        try {
            $seeder->run();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Jeu de démonstration interrompu ; la transaction a été annulée. Consultez les journaux pour le détail.');

            return self::FAILURE;
        }

        $this->info('Jeu de démonstration RH prêt.');
        $this->table(
            ['Élément', 'Créés', 'Déjà présents'],
            [
                ['Salariés', $seeder->employeesCreated, $seeder->employeesExisting],
                ['Périodes d’emploi', $seeder->periodsCreated, $seeder->periodsExisting],
                ['Équipes', $seeder->teamsCreated, $seeder->teamsExisting],
                ['Affectations', $seeder->assignmentsCreated, $seeder->assignmentsExisting],
            ],
        );
        $this->line('Aucun compte, mot de passe, rôle, permission ou événement d’audit métier n’a été créé.');
        $this->line('Ouvrez /admin/employees et /admin/teams avec un compte autorisé.');

        return self::SUCCESS;
    }
}
