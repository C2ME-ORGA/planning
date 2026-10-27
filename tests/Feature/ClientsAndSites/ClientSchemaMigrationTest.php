<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exécute un callback sur une base MySQL temporaire et isolée.
 *
 * La création et la suppression utilisent une connexion d'administration
 * distincte de la connexion transactionnelle des tests, afin que le DDL MySQL
 * ne provoque pas de commit implicite dans la transaction de RefreshDatabase.
 *
 * @param  Closure(string): void  $callback
 */
function withTemporaryMigrationDatabase(Closure $callback): void
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

it('creates the final client schema exactly once on a fresh database', function (): void {
    withTemporaryMigrationDatabase(function (): void {
        expect(Artisan::call('migrate', [
            '--database' => 'migration_test',
            '--force' => true,
        ]))->toBe(0);

        $schema = Schema::connection('migration_test');
        $columns = collect($schema->getColumns('clients'));
        $address = $columns->firstWhere('name', 'address');
        $email = $columns->firstWhere('name', 'email');
        $indexes = collect($schema->getIndexes('clients'));

        expect($schema->hasColumns('clients', [
            'id',
            'company_name',
            'contact_name',
            'address',
            'street',
            'address_complement',
            'postal_code',
            'city',
            'country',
            'phone',
            'email',
            'archived_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
            ->and($columns->pluck('name')->duplicates())->toBeEmpty()
            ->and($address['nullable'] ?? false)->toBeTrue()
            ->and($email['nullable'] ?? false)->toBeTrue()
            ->and($indexes->pluck('name')->contains('clients_archived_at_index'))->toBeTrue();

        DB::connection('migration_test')->table('clients')->insert([
            'company_name' => 'Client structuré',
            'contact_name' => 'Camille Dupont',
            'address' => null,
            'street' => '10 rue du Test',
            'address_complement' => null,
            'postal_code' => '85600',
            'city' => 'Montaigu-Vendée',
            'country' => 'France',
            'phone' => null,
            'email' => 'contact@example.test',
            'archived_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = DB::connection('migration_test')->table('clients')
            ->where('company_name', 'Client structuré')
            ->first();

        expect($client)->not->toBeNull()
            ->and($client->address)->toBeNull()
            ->and($client->street)->toBe('10 rue du Test')
            ->and($client->country)->toBe('France');
    });
});

it('upgrades an existing database without losing historical client and site data', function (): void {
    withTemporaryMigrationDatabase(function (): void {
        expect(Artisan::call('migrate', [
            '--database' => 'migration_test',
            '--force' => true,
        ]))->toBe(0);

        expect(Artisan::call('migrate:rollback', [
            '--database' => 'migration_test',
            '--path' => [
                'database/migrations/2026_10_27_000003_add_client_business_fields.php',
                'database/migrations/2026_10_27_000004_normalize_site_statuses.php',
            ],
            '--force' => true,
        ]))->toBe(0);

        $schema = Schema::connection('migration_test');

        expect($schema->hasColumn('clients', 'contact_name'))->toBeFalse()
            ->and($schema->hasColumn('clients', 'street'))->toBeFalse()
            ->and($schema->hasColumn('clients', 'postal_code'))->toBeFalse()
            ->and($schema->hasColumn('clients', 'city'))->toBeFalse()
            ->and($schema->hasColumn('clients', 'country'))->toBeFalse()
            ->and($schema->hasColumn('clients', 'archived_at'))->toBeFalse();

        $now = now();
        $clientId = DB::connection('migration_test')->table('clients')->insertGetId([
            'company_name' => 'Client historique',
            'address' => '12 quai des Tanneurs, 67000 Strasbourg',
            'phone' => '+33 3 88 00 00 01',
            'email' => 'HISTORIQUE@EXAMPLE.TEST',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('migration_test')->table('sites')->insert([
            'name' => 'Chantier historique',
            'address' => '12 quai des Tanneurs, 67000 Strasbourg',
            'client_id' => $clientId,
            'status' => 'en cours',
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        expect(Artisan::call('migrate', [
            '--database' => 'migration_test',
            '--force' => true,
        ]))->toBe(0);

        $client = DB::connection('migration_test')->table('clients')->where('id', $clientId)->first();
        $site = DB::connection('migration_test')->table('sites')->where('client_id', $clientId)->first();

        expect($client)->not->toBeNull()
            ->and($client->company_name)->toBe('Client historique')
            ->and($client->address)->toBe('12 quai des Tanneurs, 67000 Strasbourg')
            ->and($client->phone)->toBe('+33 3 88 00 00 01')
            ->and($client->email)->toBe('HISTORIQUE@EXAMPLE.TEST')
            ->and($client->street)->toBeNull()
            ->and($client->postal_code)->toBeNull()
            ->and($client->city)->toBeNull()
            ->and($client->country)->toBeNull()
            ->and($client->archived_at)->toBeNull();

        expect($site)->not->toBeNull()
            ->and($site->name)->toBe('Chantier historique')
            ->and($site->address)->toBe('12 quai des Tanneurs, 67000 Strasbourg')
            ->and($site->status)->toBe('en_cours');
    });
});
