<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;

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
    $this->actingAs($bureau)->get('/admin/sites')->assertOk()->assertSee('Chantiers');
    $this->actingAs($bureau)->get('/admin/sites/create')->assertOk();
});

it('denies client and site administration to the technical administrator', function (): void {
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $this->actingAs($admin)->get('/admin/clients')->assertForbidden();
    $this->actingAs($admin)->get('/admin/sites')->assertForbidden();
});

it('denies client and site administration to operational roles', function (): void {
    $worker = User::factory()->ouvrier()->withMultiFactorAuthentication()->create();

    $this->actingAs($worker)->get('/admin/clients')->assertForbidden();
    $this->actingAs($worker)->get('/admin/sites')->assertForbidden();
});
