<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientService;
use App\Services\Sites\SiteService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config([
        'planning.audit.key' => str_repeat('a', 32),
        'planning.audit.key_id' => 'test-key',
    ]);
});

function clientData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'C2ME Client Test',
        'address' => '10 rue du Test, 85600 Montaigu-Vendée',
        'phone' => null,
        'email' => null,
    ], $overrides);
}

function siteData(int $clientId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Chantier Test',
        'address' => '2 rue du Chantier, 85600 Montaigu-Vendée',
        'client_id' => $clientId,
        'status' => 'en_cours',
    ], $overrides);
}

it('creates and updates a client with audited changes', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(ClientService::class);

    $client = $service->create(clientData(), $bureau);
    expect($client->company_name)->toBe('C2ME Client Test');

    $service->update($client, clientData(['phone' => '0251000000']), $bureau);
    expect($client->fresh()->phone)->toBe('0251000000');
});

it('requires server-side permission to manage clients', function (): void {
    $worker = User::factory()->ouvrier()->create();

    expect(fn () => app(ClientService::class)->create(clientData(), $worker))
        ->toThrow(AuthorizationException::class);
});

it('requires the selected client and validates required site fields', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(SiteService::class);
    $client = app(ClientService::class)->create(clientData(), $bureau);

    expect(fn () => $service->create(siteData($client->id, ['name' => '']), $bureau))
        ->toThrow(ValidationException::class);
});

it('archives a site without deleting it or breaking its client relationship', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = app(SiteService::class)->create(siteData($client->id), $bureau);

    app(SiteService::class)->archive($site, $bureau);

    expect($site->fresh()->isArchived())->toBeTrue()
        ->and($site->fresh()->client->is($client))->toBeTrue()
        ->and(Site::query()->whereKey($site->id)->exists())->toBeTrue();
});

it('refuses updates to archived sites', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = app(SiteService::class)->create(siteData($client->id), $bureau);
    app(SiteService::class)->archive($site, $bureau);

    expect(fn () => app(SiteService::class)->update($site, siteData($client->id, ['name' => 'Changement']), $bureau))
        ->toThrow(ValidationException::class);
});

it('does not grant client or site management to the technical administrator', function (): void {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ClientService::class)->create(clientData(), $admin))
        ->toThrow(AuthorizationException::class);
});
