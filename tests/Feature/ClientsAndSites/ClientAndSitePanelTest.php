<?php

use App\Enums\AuditAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('exposes client and site administration to Bureau/Direction', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create([
        'company_name' => 'Client interface test',
        'address' => '1 rue du Test',
    ]);
    Site::query()->create([
        'name' => 'Chantier interface test',
        'address' => '2 rue du Test',
        'client_id' => $client->id,
        'status' => 'test',
    ]);

    $this->actingAs($bureau)->get('/admin/clients')->assertOk()->assertSee('Clients');
    $this->actingAs($bureau)->get('/admin/clients/create')->assertOk();
    $this->actingAs($bureau)->get('/admin/clients/'.$client->id.'/edit')->assertOk();
    $this->actingAs($bureau)->get('/admin/sites')->assertOk()->assertSee('Chantiers');
    $this->actingAs($bureau)->get('/admin/sites/create')->assertOk();
});

it('denies client and site administration to the technical administrator', function (): void {
    $client = Client::query()->create([
        'company_name' => 'Client protégé',
        'address' => '3 rue du Test',
    ]);
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $this->actingAs($admin)->get('/admin/clients')->assertForbidden();
    $this->actingAs($admin)->get('/admin/clients/'.$client->id.'/edit')->assertForbidden();
    $this->actingAs($admin)->get('/admin/sites')->assertForbidden();

    expect($admin->can('update', $client))->toBeFalse();
});

it('denies client and site administration to operational roles', function (): void {
    $client = Client::query()->create([
        'company_name' => 'Client protégé',
        'address' => '4 rue du Test',
    ]);
    $worker = User::factory()->ouvrier()->withMultiFactorAuthentication()->create();

    $this->actingAs($worker)->get('/admin/clients')->assertForbidden();
    $this->actingAs($worker)->get('/admin/clients/'.$client->id.'/edit')->assertForbidden();
    $this->actingAs($worker)->get('/admin/sites')->assertForbidden();

    expect($worker->can('update', $client))->toBeFalse();
});

it('exposes client editing through the list to Bureau/Direction', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create([
        'company_name' => 'Client modifiable',
        'address' => '5 rue du Test',
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListClients::class)
        ->assertTableActionVisible('edit', $client)
        ->assertTableActionHasUrl(
            'edit',
            ClientResource::getUrl('edit', ['record' => $client]),
            $client,
        );
});

it('records a client update through the Filament edit page with audit', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create([
        'company_name' => 'Client initial',
        'address' => '6 rue du Test',
    ]);

    $this->actingAs($bureau);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm([
            'company_name' => 'Client modifié',
            'address' => '7 rue du Test',
            'phone' => '0251000000',
            'email' => 'contact@example.test',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($client->fresh()->company_name)->toBe('Client modifié');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::ClientUpdated->value,
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);
});

it('keeps archived sites consultable while blocking their modification', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create([
        'company_name' => 'Client archive test',
        'address' => '8 rue du Test',
    ]);
    $site = Site::query()->create([
        'name' => 'Chantier archivé test',
        'address' => '9 rue du Test',
        'client_id' => $client->id,
        'status' => 'termine',
        'archived_at' => now(),
    ]);

    $this->actingAs($bureau)->get('/admin/sites')->assertOk()->assertSee('Chantier archivé test');
    $this->actingAs($bureau)->get('/admin/sites/'.$site->id.'/edit')->assertForbidden();

    expect($bureau->can('view', $site))->toBeTrue()
        ->and($bureau->can('update', $site))->toBeFalse()
        ->and($bureau->can('archive', $site))->toBeFalse();
});

it('exposes no physical deletion for clients and sites', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create([
        'company_name' => 'Client non supprimable',
        'address' => '10 rue du Test',
    ]);
    $site = Site::query()->create([
        'name' => 'Chantier non supprimable',
        'address' => '11 rue du Test',
        'client_id' => $client->id,
        'status' => 'actif',
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListClients::class)
        ->assertTableActionDoesNotExist('delete', null, $client);

    Livewire::test(ListSites::class)
        ->assertTableActionDoesNotExist('delete', null, $site);

    expect($bureau->can('delete', $client))->toBeFalse()
        ->and($bureau->can('delete', $site))->toBeFalse();
});
