<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

it('allows active and activated terrain roles to sign in to the planning', function (Role $role) {
    $user = User::factory()->role($role)->create([
        'email' => strtolower($role->value).'@example.test',
        'password' => Hash::make('Terrain-Passw0rd!'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('planning.mobile'));

    $this->assertAuthenticatedAs($user, 'web');
})->with([
    'ouvrier' => Role::Ouvrier,
    'chef_equipe' => Role::ChefEquipe,
    'conducteur_travaux' => Role::Conducteur,
]);

it('rejects incorrect credentials with a generic error', function () {
    $user = User::factory()->ouvrier()->create([
        'email' => 'ouvrier@example.test',
        'password' => Hash::make('Correct-Passw0rd!'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'Incorrect-Passw0rd!',
    ])->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

it('rejects inactive and not-yet-activated accounts', function (User $user) {
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('web');
})->with([
    'inactive account' => fn () => User::factory()->ouvrier()->inactive()->create(),
    'unactivated account' => fn () => User::factory()->ouvrier()->unactivated()->create(),
]);

it('keeps terrain users outside the Filament panel after login', function () {
    $user = User::factory()->ouvrier()->create();

    $this->actingAs($user, 'web')
        ->get('/admin')
        ->assertForbidden();
});

it('redirects unauthenticated planning visitors to the terrain login', function () {
    $this->get('/planning')->assertRedirect('/login');
});

it('logs out the web session', function () {
    $user = User::factory()->ouvrier()->create();

    $this->actingAs($user, 'web')
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('does not allow administrative roles to bypass Filament multi-factor authentication through terrain login', function (Role $role) {
    $user = User::factory()->role($role)->create([
        'email' => strtolower($role->value).'@example.test',
        'password' => Hash::make('Admin-Passw0rd!'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Admin-Passw0rd!',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('web');
})->with([
    'administrateur' => Role::Admin,
    'bureau_direction' => Role::BureauDirection,
]);
