<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Crée une base MySQL temporaire et isolée pour vérifier une migration.
 *
 * La base est supprimée dans tous les cas. La création et la suppression
 * passent par une connexion d'administration distincte, car le DDL MySQL
 * provoque un commit implicite dans la transaction de RefreshDatabase.
 */
final class TemporaryDatabase
{
    /**
     * @param  Closure(string): void  $callback
     */
    public static function run(Closure $callback): void
    {
        $database = 'planning_c2me_migration_'.bin2hex(random_bytes(6));
        $base = config('database.connections.mysql');

        config([
            'database.connections.migration_admin' => array_merge($base, [
                'database' => 'mysql',
                'url' => null,
            ]),
            'database.connections.migration_test' => array_merge($base, [
                'database' => $database,
                'url' => null,
            ]),
        ]);

        DB::purge('migration_admin');
        DB::purge('migration_test');

        DB::connection('migration_admin')->statement(
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        );

        try {
            $callback($database);
        } finally {
            DB::purge('migration_test');
            DB::connection('migration_admin')->statement("DROP DATABASE IF EXISTS `{$database}`");
            DB::purge('migration_admin');

            config([
                'database.connections.migration_admin' => null,
                'database.connections.migration_test' => null,
            ]);
        }
    }

    public static function migrate(string $database): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'migration_test',
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException("La migration de la base {$database} a échoué (code {$exitCode}).");
        }
    }
}
