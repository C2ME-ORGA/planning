<?php

namespace App\Console\Commands;

use App\Services\Accounts\AuthenticationGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Applique ou vérifie les migrations de sécurité indépendamment des
 * migrations métier.
 *
 * L'ordre des fichiers place l'état global avant les feuilles de temps, mais
 * `session_version` est daté après elles. Cette commande garantit que les deux
 * évolutions peuvent être appliquées explicitement, dans des batches dédiés,
 * avant le déploiement du code.
 */
class InstallSecuritySchemaCommand extends Command
{
    protected $signature = 'planning:security:install
        {--check : Vérifie seulement le schéma sans appliquer de migration}';

    protected $description = 'Applique ou vérifie les migrations de sécurité de l’authentification.';

    public function handle(): int
    {
        if (! $this->option('check')) {
            $paths = [
                'database/migrations/2026_10_27_000007_create_authentication_state_table.php',
                'database/migrations/2026_11_02_000001_add_session_version_to_users_table.php',
            ];

            foreach ($paths as $path) {
                $status = $this->call('migrate', [
                    '--path' => $path,
                    '--force' => true,
                ]);

                if ($status !== self::SUCCESS) {
                    $this->error("La migration de sécurité a échoué : {$path}");

                    return self::FAILURE;
                }
            }
        }

        return $this->verify();
    }

    private function verify(): int
    {
        if (! Schema::hasTable(AuthenticationGeneration::TABLE)) {
            $this->error('Table authentication_state absente. Exécutez planning:security:install.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('users', 'session_version')) {
            $this->error('Colonne users.session_version absente. Exécutez planning:security:install.');

            return self::FAILURE;
        }

        try {
            $generation = app(AuthenticationGeneration::class)->current();
        } catch (Throwable $exception) {
            $this->error('État global d’authentification invalide : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Schéma de sécurité valide. Génération globale : {$generation}.");

        return self::SUCCESS;
    }
}
