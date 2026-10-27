<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function runDemoDataset(): void
{
    expect(Artisan::call('planning:demo:clients-sites', ['--confirm' => true]))->toBe(Command::SUCCESS);
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
            ->and($client->phone === null || mb_strlen((string) $client->phone) <= 40)->toBeTrue()
            ->and($client->email === null || str_ends_with($client->email, '.example.test'))->toBeTrue();
    }

    foreach ($sites as $site) {
        expect($site->client)->not->toBeNull()
            ->and($site->client->company_name)->toEndWith('(démo)')
            ->and(mb_strlen($site->name))->toBeLessThanOrEqual(190)
            ->and(mb_strlen($site->address))->toBeLessThanOrEqual(500)
            ->and(mb_strlen($site->status))->toBeLessThanOrEqual(40);
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
        ->and($existingSite->fresh()?->client_id)->toBe($existingClient->id);
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
