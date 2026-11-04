<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Support\DemoData\DemoDatasetEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function runDemoDataset(): void
{
    expect(Artisan::call('planning:demo:clients-sites', ['--confirm' => true]))->toBe(Command::SUCCESS);
}

/**
 * Force une cible SQLite et une liste blanche pour la durée du callback, puis
 * restaure la configuration MySQL des tests même en cas d'échec.
 *
 * @param  array<int, string>  $allowedPaths
 */
function withDemoSqliteTarget(string $path, array $allowedPaths, Closure $callback): mixed
{
    $originalDefault = config('database.default');
    $originalSqlite = config('database.connections.sqlite');
    $originalAllowed = config('planning.demo.allowed_sqlite_paths');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $path,
        'planning.demo.allowed_sqlite_paths' => $allowedPaths,
    ]);

    try {
        return $callback();
    } finally {
        config([
            'database.default' => $originalDefault,
            'database.connections.sqlite' => $originalSqlite,
            'planning.demo.allowed_sqlite_paths' => $originalAllowed,
        ]);
        DB::purge('sqlite');
    }
}

it('creates the documented demo dataset without audit events or accounts', function (): void {
    runDemoDataset();

    $clients = Client::query()->where('company_name', 'like', '%(démo)')->get();
    $sites = Site::query()->where('name', 'like', '%(démo)')->get();

    expect($clients)->toHaveCount(16)
        ->and($sites)->toHaveCount(40)
        ->and(Client::query()->count())->toBe(16)
        ->and(Site::query()->count())->toBe(40)
        ->and($sites->filter(fn (Site $site): bool => $site->isArchived()))->toHaveCount(6)
        ->and($sites->filter(fn (Site $site): bool => ! $site->isArchived()))->toHaveCount(34)
        ->and($clients->filter(fn (Client $client): bool => $client->sites()->count() === 0))->toHaveCount(2)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->count())->toBe(0);

    foreach ($clients as $client) {
        expect($client->company_name)->toEndWith('(démo)')
            ->and(mb_strlen($client->company_name))->toBeLessThanOrEqual(190)
            ->and(mb_strlen($client->address))->toBeLessThanOrEqual(500)
            ->and($client->contact_name === null || mb_strlen($client->contact_name) <= 190)->toBeTrue()
            ->and($client->street)->not->toBeEmpty()
            ->and($client->postal_code)->not->toBeEmpty()
            ->and($client->city)->not->toBeEmpty()
            ->and($client->country)->toBe('France')
            ->and($client->phone === null || mb_strlen((string) $client->phone) <= 40)->toBeTrue()
            ->and($client->email)->not->toBeNull()
            ->and(filter_var($client->email, FILTER_VALIDATE_EMAIL))->not->toBeFalse()
            ->and(str_ends_with((string) $client->email, '.example.test'))->toBeTrue();
    }

    foreach ($sites as $site) {
        expect($site->client)->not->toBeNull()
            ->and($site->client->company_name)->toEndWith('(démo)')
            ->and(mb_strlen($site->name))->toBeLessThanOrEqual(190)
            ->and(mb_strlen($site->address))->toBeLessThanOrEqual(500)
            ->and($site->statusEnum())->not->toBeNull();
    }
});

it('is idempotent and preserves records outside the demo dataset', function (): void {
    $existingClient = Client::query()->create([
        'company_name' => 'Client existant hors démonstration',
        'address' => '1 rue du Existant, 44100 Nantes',
    ]);

    $existingSite = Site::query()->create([
        'name' => 'Chantier existant hors démonstration',
        'address' => '1 rue du Existant, 44100 Nantes',
        'client_id' => $existingClient->id,
        'status' => 'en cours',
    ]);

    runDemoDataset();

    $firstClientIds = Client::query()->where('company_name', 'like', '%(démo)')->orderBy('id')->pluck('id')->all();
    $firstSiteIds = Site::query()->where('name', 'like', '%(démo)')->orderBy('id')->pluck('id')->all();

    runDemoDataset();

    expect(Client::query()->where('company_name', 'like', '%(démo)')->orderBy('id')->pluck('id')->all())
        ->toBe($firstClientIds)
        ->and(Site::query()->where('name', 'like', '%(démo)')->orderBy('id')->pluck('id')->all())
        ->toBe($firstSiteIds)
        ->and(Client::query()->where('company_name', 'like', '%(démo)')->count())->toBe(16)
        ->and(Site::query()->where('name', 'like', '%(démo)')->count())->toBe(40)
        ->and($existingClient->fresh()?->company_name)->toBe('Client existant hors démonstration')
        ->and($existingSite->fresh()?->name)->toBe('Chantier existant hors démonstration')
        ->and($existingSite->fresh()?->client_id)->toBe($existingClient->id)
        ->and($existingClient->fresh()?->street)->toBeNull()
        ->and($existingClient->fresh()?->email)->toBeNull();
});

it('completes structured fields of an existing demo client without overwriting entered values', function (): void {
    $client = Client::query()->create([
        'company_name' => 'Atelier du Vieux Canal (démo)',
        'address' => '12 quai des Tanneurs, 67000 Strasbourg',
        'contact_name' => 'Contact déjà saisi',
    ]);

    runDemoDataset();

    expect($client->fresh()->contact_name)->toBe('Contact déjà saisi')
        ->and($client->fresh()->street)->toBe('12 quai des Tanneurs')
        ->and($client->fresh()->postal_code)->toBe('67000')
        ->and($client->fresh()->city)->toBe('Strasbourg')
        ->and($client->fresh()->country)->toBe('France')
        ->and($client->fresh()->email)->toBe('contact@atelier-vieux-canal.example.test');
});

it('shows the target database and refuses to write without confirmation', function (): void {
    $this->artisan('planning:demo:clients-sites')
        ->expectsOutputToContain('planning_c2me_test')
        ->expectsOutputToContain('--confirm')
        ->assertFailed();

    expect(Client::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('refuses to run outside local or testing', function (): void {
    $app = app();
    $app->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('planning:demo:clients-sites', ['--confirm' => true])
            ->expectsOutputToContain('refusée')
            ->assertFailed();
    } finally {
        $app->detectEnvironment(fn (): string => 'testing');
    }

    expect(Client::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('refuses a database that is not explicitly authorized', function (): void {
    $original = config('database.connections.mysql.database');

    config(['database.connections.mysql.database' => 'planning_production']);

    try {
        $this->artisan('planning:demo:clients-sites', ['--confirm' => true])
            ->expectsOutputToContain('non autorisée')
            ->assertFailed();
    } finally {
        config(['database.connections.mysql.database' => $original]);
    }

    expect(Client::query()->count())->toBe(0);
});

it('refuses a remote database host', function (): void {
    $original = config('database.connections.mysql.host');

    config(['database.connections.mysql.host' => '10.20.30.40']);

    try {
        $this->artisan('planning:demo:clients-sites', ['--confirm' => true])
            ->expectsOutputToContain('non autorisé')
            ->assertFailed();
    } finally {
        config(['database.connections.mysql.host' => $original]);
    }

    expect(Client::query()->count())->toBe(0);
});

it('accepts an explicitly allowed sqlite file without creating it', function (): void {
    $directory = storage_path('framework/testing');
    $target = $directory.DIRECTORY_SEPARATOR.'demo-allowed-'.bin2hex(random_bytes(6)).'.sqlite';

    withDemoSqliteTarget($target, [$target], function () use ($target, $directory): void {
        $state = (new DemoDatasetEnvironment)->inspect();
        $expected = realpath($directory);

        expect($expected)->not->toBeFalse()
            ->and($state['safe'])->toBeTrue()
            ->and($state['reasons'])->toBe([])
            ->and($state['resolved_database'])->toBe($expected.DIRECTORY_SEPARATOR.basename($target))
            ->and((new DemoDatasetEnvironment)->describe())->toContain(basename($target));
    });

    expect(file_exists($target))->toBeFalse();
});

it('refuses a sqlite file outside the explicit allow list without writing data', function (): void {
    $target = storage_path('framework/testing/demo-refused-'.bin2hex(random_bytes(6)).'.sqlite');
    $otherAllowed = storage_path('framework/testing/demo-other-'.bin2hex(random_bytes(6)).'.sqlite');

    withDemoSqliteTarget($target, [$otherAllowed], function (): void {
        $this->artisan('planning:demo:clients-sites', ['--confirm' => true])
            ->expectsOutputToContain('SQLite non autorisé')
            ->assertFailed();
    });

    expect(file_exists($target))->toBeFalse()
        ->and(Client::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('refuses an empty sqlite path', function (): void {
    withDemoSqliteTarget('', [], function (): void {
        $state = (new DemoDatasetEnvironment)->inspect();

        expect($state['safe'])->toBeFalse()
            ->and(implode(' ; ', $state['reasons']))->toContain('chemin SQLite absent');
    });
});

it('refuses a relative sqlite path', function (): void {
    withDemoSqliteTarget('database/database.sqlite', [], function (): void {
        $state = (new DemoDatasetEnvironment)->inspect();

        expect($state['safe'])->toBeFalse()
            ->and(implode(' ; ', $state['reasons']))->toContain('chemin SQLite relatif non autorisé');
    });
});

it('refuses a sqlite path containing a parent directory segment', function (): void {
    $target = storage_path('framework/testing/demo-dotdot-'.bin2hex(random_bytes(6)).'.sqlite');
    $parent = dirname($target);
    $ambiguous = $parent.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($parent).DIRECTORY_SEPARATOR.basename($target);

    withDemoSqliteTarget($ambiguous, [$target], function (): void {
        $state = (new DemoDatasetEnvironment)->inspect();

        expect($state['safe'])->toBeFalse()
            ->and(implode(' ; ', $state['reasons']))->toContain('segment « .. » interdit');
    });

    expect(file_exists($target))->toBeFalse();
});

it('refuses a symlinked sqlite path even when the link is allow-listed', function (): void {
    $directory = storage_path('framework/testing/demo-symlink-'.bin2hex(random_bytes(6)));

    if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
        $this->markTestSkipped('Répertoire temporaire indisponible.');
    }

    $target = $directory.DIRECTORY_SEPARATOR.'real.sqlite';
    $link = $directory.DIRECTORY_SEPARATOR.'link.sqlite';
    file_put_contents($target, '');

    if (! @symlink($target, $link)) {
        @unlink($target);
        @rmdir($directory);

        $this->markTestSkipped('Liens symboliques indisponibles sur cette plateforme.');
    }

    try {
        withDemoSqliteTarget($link, [$link], function (): void {
            $state = (new DemoDatasetEnvironment)->inspect();

            expect($state['safe'])->toBeFalse()
                ->and(implode(' ; ', $state['reasons']))->toContain('lien symbolique SQLite refusé');
        });
    } finally {
        @unlink($link);
        @unlink($target);
        @rmdir($directory);
    }
});

it('refuses sqlite memory databases and uri targets', function (string $path): void {
    withDemoSqliteTarget($path, [$path], function (): void {
        $state = (new DemoDatasetEnvironment)->inspect();

        expect($state['safe'])->toBeFalse()
            ->and(implode(' ; ', $state['reasons']))->toContain('mémoire ou URI refusée');
    });
})->with([
    ':memory:',
    'file:demo.sqlite',
]);

it('keeps the existing client and site permissions on demo records', function (): void {
    runDemoDataset();

    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();
    $client = Client::query()->where('company_name', 'like', '%(démo)')->firstOrFail();
    $archived = Site::query()->whereNotNull('archived_at')->firstOrFail();

    $this->actingAs($bureau)->get('/admin/clients')->assertOk();
    $this->actingAs($bureau)->get('/admin/sites')->assertOk();
    $this->actingAs($bureau)->get('/admin/sites/'.$archived->id.'/edit')->assertForbidden();

    $this->actingAs($admin)->get('/admin/clients')->assertForbidden();
    $this->actingAs($admin)->get('/admin/sites')->assertForbidden();

    expect($bureau->can('update', $client))->toBeTrue()
        ->and($admin->can('update', $client))->toBeFalse();
});
