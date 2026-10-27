<?php

use App\Enums\SiteStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function panelClientData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Client interface test',
        'contact_name' => null,
        'street' => '1 rue du Test',
        'address_complement' => null,
        'postal_code' => '85600',
        'city' => 'Montaigu-Vendée',
        'country' => 'France',
        'phone' => null,
        'email' => 'interface@example.test',
    ], $overrides);
}

it('exposes client and site administration to Bureau/Direction', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData());
    Site::query()->create([
        'name' => 'Chantier interface test',
        'address' => '2 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::APreparer->value,
    ]);

    $this->actingAs($bureau)->get('/admin/clients')->assertOk()->assertSee('Clients');
    $this->actingAs($bureau)->get('/admin/clients/create')->assertOk();
    $this->actingAs($bureau)->get('/admin/clients/'.$client->id)->assertOk();
    $this->actingAs($bureau)->get('/admin/clients/'.$client->id.'/edit')->assertOk();
    $this->actingAs($bureau)->get('/admin/sites')->assertOk()->assertSee('Chantiers');
    $this->actingAs($bureau)->get('/admin/sites/create')->assertOk();
});

it('denies client and site administration to the technical administrator', function (): void {
    $client = Client::query()->create(panelClientData(['company_name' => 'Client protégé']));
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $this->actingAs($admin)->get('/admin/clients')->assertForbidden();
    $this->actingAs($admin)->get('/admin/clients/'.$client->id)->assertForbidden();
    $this->actingAs($admin)->get('/admin/clients/'.$client->id.'/edit')->assertForbidden();
    $this->actingAs($admin)->get('/admin/sites')->assertForbidden();

    expect($admin->can('update', $client))->toBeFalse()
        ->and($admin->can('restore', $client))->toBeFalse();
});

it('denies client and site administration to operational roles', function (): void {
    $client = Client::query()->create(panelClientData(['company_name' => 'Client protégé']));
    $worker = User::factory()->ouvrier()->withMultiFactorAuthentication()->create();

    $this->actingAs($worker)->get('/admin/clients')->assertForbidden();
    $this->actingAs($worker)->get('/admin/clients/'.$client->id)->assertForbidden();
    $this->actingAs($worker)->get('/admin/clients/'.$client->id.'/edit')->assertForbidden();
    $this->actingAs($worker)->get('/admin/sites')->assertForbidden();

    expect($worker->can('update', $client))->toBeFalse()
        ->and($worker->can('restore', $client))->toBeFalse();
});

it('exposes client editing through the list to Bureau/Direction', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData(['company_name' => 'Client modifiable']));

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
    $client = Client::query()->create(panelClientData(['company_name' => 'Client initial']));

    $this->actingAs($bureau);

    Livewire::test(EditClient::class, ['record' => $client->id])
        ->fillForm([
            'company_name' => 'Client modifié',
            'street' => '7 rue du Test',
            'postal_code' => '85600',
            'city' => 'Montaigu-Vendée',
            'country' => 'France',
            'phone' => '0251000000',
            'email' => 'Contact@Example.Test',
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($client->fresh()->company_name)->toBe('Client modifié')
        ->and($client->fresh()->email)->toBe('contact@example.test');

    $this->assertDatabaseHas('audit_events', [
        'action' => 'client.updated',
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);
});

it('creates a client with France as the default country and warns about a duplicate without blocking', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    Client::query()->create(panelClientData([
        'company_name' => 'Doublon potentiel',
        'email' => 'doublon@example.test',
    ]));

    $this->actingAs($bureau);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'company_name' => '  doublon potentiel  ',
            'street' => '2 rue du Doublon',
            'postal_code' => '85600',
            'city' => 'Montaigu-Vendée',
            'email' => 'DOUBLON@example.test',
        ])
        ->call('create')
        ->assertHasNoErrors()
        ->assertNotified('Doublon potentiel détecté');

    $created = Client::query()->where('street', '2 rue du Doublon')->firstOrFail();

    expect(Client::query()->count())->toBe(2)
        ->and($created->country)->toBe('France')
        ->and($created->email)->toBe('doublon@example.test');
});

it('keeps archived clients visible, consultable and not editable', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData([
        'company_name' => 'Client archivé',
        'contact_name' => 'Contact archivé',
    ]));
    $client->forceFill(['archived_at' => now()])->save();

    $this->actingAs($bureau);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$client])
        ->assertTableActionVisible('view', $client)
        ->assertTableActionHidden('edit', $client)
        ->assertTableActionVisible('restore', $client)
        ->assertTableActionHidden('archive', $client);

    $this->actingAs($bureau)->get('/admin/clients/'.$client->id)
        ->assertOk()
        ->assertSee('Client archivé')
        ->assertSee('Contact archivé')
        ->assertSee('Archivé');

    $this->actingAs($bureau)->get('/admin/clients/'.$client->id.'/edit')
        ->assertForbidden();

    expect($bureau->can('update', $client))->toBeFalse()
        ->and($bureau->can('restore', $client))->toBeTrue();
});

it('changes a site status through the list and audits the transition', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData());
    $site = Site::query()->create([
        'name' => 'Chantier à planifier',
        'address' => '3 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::APreparer->value,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListSites::class)
        ->assertTableActionVisible('transition', $site)
        ->assertTableActionHidden('reopen', $site)
        ->callTableAction('transition', $site, ['target' => SiteStatus::Planifie->value])
        ->assertHasNoErrors();

    expect($site->fresh()->status)->toBe(SiteStatus::Planifie->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'site.status_changed',
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
    ]);
});

it('exposes reopening only on terminal sites and blocks ordinary transitions', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData());
    $site = Site::query()->create([
        'name' => 'Chantier terminé',
        'address' => '4 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::Termine->value,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListSites::class)
        ->assertTableActionHidden('transition', $site)
        ->assertTableActionVisible('reopen', $site)
        ->callTableAction('reopen', $site, [
            'target' => SiteStatus::EnCours->value,
            'reason' => 'Travaux complémentaires demandés.',
        ])
        ->assertHasNoErrors();

    expect($site->fresh()->status)->toBe(SiteStatus::EnCours->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'site.reopened',
        'subject_id' => (string) $site->id,
        'reason' => 'Travaux complémentaires demandés.',
    ]);
});

it('refuses to archive a client with an active site from the list', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData());
    Site::query()->create([
        'name' => 'Chantier actif',
        'address' => '5 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::EnCours->value,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListClients::class)
        ->callTableAction('archive', $client)
        ->assertNotified('Opération refusée');

    expect($client->fresh()->isArchived())->toBeFalse();
});

it('keeps archived sites consultable while blocking their modification', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData(['company_name' => 'Client archive test']));
    $site = Site::query()->create([
        'name' => 'Chantier archivé test',
        'address' => '9 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::Termine->value,
        'archived_at' => now(),
    ]);

    $this->actingAs($bureau)->get('/admin/sites')->assertOk()->assertSee('Chantier archivé test');
    $this->actingAs($bureau)->get('/admin/sites/'.$site->id.'/edit')->assertForbidden();

    expect($bureau->can('view', $site))->toBeTrue()
        ->and($bureau->can('update', $site))->toBeFalse()
        ->and($bureau->can('archive', $site))->toBeFalse()
        ->and($bureau->can('transition', $site))->toBeFalse()
        ->and($bureau->can('reopen', $site))->toBeFalse();
});

it('exposes no physical deletion for clients and sites', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $client = Client::query()->create(panelClientData(['company_name' => 'Client non supprimable']));
    $site = Site::query()->create([
        'name' => 'Chantier non supprimable',
        'address' => '11 rue du Test',
        'client_id' => $client->id,
        'status' => SiteStatus::APreparer->value,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ListClients::class)
        ->assertTableActionDoesNotExist('delete', null, $client);

    Livewire::test(ListSites::class)
        ->assertTableActionDoesNotExist('delete', null, $site);

    expect($bureau->can('delete', $client))->toBeFalse()
        ->and($bureau->can('delete', $site))->toBeFalse();
});
