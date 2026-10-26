<?php

use App\Models\User;

beforeEach(function () {
    config(['planning.admin_emails' => ['admin@example.test']]);
});

it('redirects guests to the Filament login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('renders the Filament login page', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Planning C2ME');
});

it('forbids authenticated users outside the allowlist', function () {
    $user = User::factory()->create(['email' => 'user@example.test']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

it('allows allowlisted users to access the panel', function () {
    $user = User::factory()->create(['email' => 'admin@example.test']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
});

it('denies access when the allowlist is empty', function () {
    config(['planning.admin_emails' => []]);

    $user = User::factory()->create(['email' => 'admin@example.test']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});
