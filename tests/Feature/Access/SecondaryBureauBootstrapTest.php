<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\Notification;

it('creates an additional Bureau account with an authorized Bureau actor', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create([
        'email' => 'direction@example.test',
    ]);

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'suppleant@example.test',
        '--name' => 'Compte suppléant',
        '--additional' => true,
        '--actor' => $actor->email,
        '--confirm' => true,
    ])->assertExitCode(0);

    $suppleant = User::query()->where('email', 'suppleant@example.test')->firstOrFail();

    expect($suppleant->name)->toBe('Compte suppléant')
        ->and($suppleant->role)->toBe(Role::BureauDirection)
        ->and($suppleant->is_active)->toBeTrue()
        ->and($suppleant->activated_at)->toBeNull()
        ->and($suppleant->requiresMultiFactorAuthentication())->toBeTrue()
        ->and($suppleant->hasPermission(Permission::AccountsCreate))->toBeTrue()
        ->and($suppleant->hasPermission(Permission::RolesAssign))->toBeTrue()
        ->and($suppleant->id)->not->toBe($actor->id);

    Notification::assertSentTo($suppleant, AccountActivationNotification::class);

    $event = AuditEvent::query()
        ->where('action', AuditAction::AccountCreated->value)
        ->where('subject_id', (string) $suppleant->id)
        ->firstOrFail();

    expect($event->actor_id)->toBe($actor->id)
        ->and($event->actor_role)->toBe(Role::BureauDirection->value)
        ->and($event->changes['role'])->toBe(Role::BureauDirection->value);
});

it('refuses an additional Bureau account without an actor', function () {
    Notification::fake();

    User::factory()->bureauDirection()->create();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'suppleant@example.test',
        '--name' => 'Compte suppléant',
        '--additional' => true,
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'suppleant@example.test']);
});

it('refuses an administrator as the actor for an additional Bureau account', function () {
    Notification::fake();

    User::factory()->bureauDirection()->create();
    $admin = User::factory()->admin()->create();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'suppleant@example.test',
        '--name' => 'Compte suppléant',
        '--additional' => true,
        '--actor' => $admin->email,
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'suppleant@example.test']);
});

it('refuses an inactive Bureau actor for an additional Bureau account', function () {
    Notification::fake();

    User::factory()->bureauDirection()->create();
    $actor = User::factory()->bureauDirection()->inactive()->create();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'suppleant@example.test',
        '--name' => 'Compte suppléant',
        '--additional' => true,
        '--actor' => $actor->email,
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'suppleant@example.test']);
});

it('refuses an already used email when creating an additional Bureau account', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create();
    User::factory()->ouvrier()->create(['email' => 'existing@example.test']);

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'EXISTING@example.test',
        '--name' => 'Compte doublon',
        '--additional' => true,
        '--actor' => $actor->email,
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(User::query()->where('email', 'existing@example.test')->count())->toBe(1);
});

it('requires explicit confirmation for an additional Bureau account', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'suppleant@example.test',
        '--name' => 'Compte suppléant',
        '--additional' => true,
        '--actor' => $actor->email,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'suppleant@example.test']);
});

it('rejects actor option in first-account bootstrap mode', function () {
    Notification::fake();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'direction@example.test',
        '--name' => 'Compte initial',
        '--actor' => 'acteur@example.test',
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'direction@example.test']);
});
