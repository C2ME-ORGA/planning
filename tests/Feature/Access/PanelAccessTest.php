<?php

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;

it('redirects guests to the Filament login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('renders the Filament login page', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Planning C2ME');
});

it('forbids roles without administration panel access', function () {
    $user = User::factory()->ouvrier()->withMultiFactorAuthentication()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('requires a second factor before opening the panel', function () {
    $admin = User::factory()->admin()->create();
    $bureau = User::factory()->bureauDirection()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    $this->actingAs($bureau)
        ->get('/admin')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('keeps every non-panel role outside the panel even with a second factor', function (Role $role) {
    $user = User::factory()->role($role)->withMultiFactorAuthentication()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
})->with([
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);

it('requires a second factor exactly for the roles allowed in the panel', function () {
    foreach (Role::cases() as $role) {
        $user = User::factory()->role($role)->create();

        expect($user->requiresMultiFactorAuthentication())
            ->toBe(in_array($role, [Role::Admin, Role::BureauDirection], true), $role->value);
    }
});

it('shows the audit log but no account management to the administrator', function () {
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Journal d’audit');
    $this->actingAs($admin)->get('/admin/audit-events')->assertOk();
    $this->actingAs($admin)->get('/admin/users')->assertForbidden();
});

it('allows the Bureau to manage accounts and read the audit log', function () {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();

    $this->actingAs($bureau)->get('/admin')->assertOk()->assertSee('Comptes');
    $this->actingAs($bureau)->get('/admin/users')->assertOk();
    $this->actingAs($bureau)->get('/admin/audit-events')->assertOk();
});

it('logs out a deactivated account before any panel page is served', function () {
    $user = User::factory()->bureauDirection()
        ->withMultiFactorAuthentication()
        ->inactive()
        ->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});
