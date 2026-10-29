<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Accounts\AccountActivationService;
use App\Services\Accounts\AccountAdministration;
use App\Services\Accounts\AccountProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

function insertSessionFor(User $user, string $id = 'session-test'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

it('creates an account without a known password and sends a single-use activation link', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create();
    $user = app(AccountProvisioner::class)->create(
        'Nouvelle Personne',
        'Nouvelle.Personne@Example.Test',
        Role::Ouvrier,
        $actor,
    );

    expect($user->email)->toBe('nouvelle.personne@example.test')
        ->and($user->activated_at)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasPermission(Permission::TimesheetOwn))->toBeTrue();

    Notification::assertSentTo($user, AccountActivationNotification::class);

    $token = app(AccountActivationService::class)->send($user, $actor);

    $this->get('/activation/'.$token)
        ->assertOk()
        ->assertSee('Activer votre compte');

    $this->post('/activation/'.$token, [
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
    ])->assertRedirect('/login');

    $user->refresh();

    expect($user->activated_at)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Str0ng-Passw0rd!', $user->password))->toBeTrue();

    $this->post('/activation/'.$token, [
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
    ])->assertStatus(410);
});

it('keeps email addresses globally unique, including inactive accounts', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create();
    User::factory()->ouvrier()->inactive()->create(['email' => 'unique@example.test']);

    expect(fn () => app(AccountProvisioner::class)->create(
        'Doublon',
        'UNIQUE@Example.Test',
        Role::Ouvrier,
        $actor,
    ))->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'unique@example.test')->count())->toBe(1);
});

it('rejects weak passwords and expired or superseded activation links', function () {
    Notification::fake();

    $actor = User::factory()->bureauDirection()->create();
    $user = app(AccountProvisioner::class)->create('Invitée', 'invitee@example.test', Role::Ouvrier, $actor);
    $service = app(AccountActivationService::class);
    $token = $service->send($user, $actor);

    $this->post('/activation/'.$token, [
        'password' => 'faible',
        'password_confirmation' => 'faible',
    ])->assertSessionHasErrors('password');

    $newToken = $service->send($user, $actor);

    expect($service->findUsable($token))->toBeNull()
        ->and($service->findUsable($newToken))->not->toBeNull();

    $this->travel(25)->hours();

    expect($service->findUsable($newToken))->toBeNull();
    $this->get('/activation/'.$newToken)->assertStatus(410);

    expect(fn () => $service->send($user->fresh()->forceFill(['activated_at' => now()]), $actor))
        ->toThrow(RuntimeException::class);
});

it('immediately revokes sessions when an account is deactivated', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-target');

    app(AccountAdministration::class)->deactivate($target, 'Départ de l’entreprise.', $actor);

    $target->refresh();

    expect($target->is_active)->toBeFalse()
        ->and($target->deactivated_at)->not->toBeNull()
        ->and($target->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $target->id,
        'reason' => 'Départ de l’entreprise.',
    ]);

    $this->actingAs($target)->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest();
});

it('prevents self administration and protects the last Bureau account', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $inactiveBureau = User::factory()->bureauDirection()->inactive()->create();
    $admin = User::factory()->admin()->create();
    $service = app(AccountAdministration::class);

    expect(fn () => $service->changeRole($bureau, Role::Ouvrier, $bureau))
        ->toThrow(RuntimeException::class);

    expect(fn () => $service->deactivate($bureau, 'Tentative de désactivation.', $inactiveBureau))
        ->toThrow(RuntimeException::class);

    expect(fn () => $service->changeRole($bureau, Role::Ouvrier, $inactiveBureau))
        ->toThrow(RuntimeException::class);

    expect($admin->can('create', User::class))->toBeFalse()
        ->and($bureau->can('create', User::class))->toBeTrue();
});

it('enforces account permissions inside the services, not only in the panel', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $admin = User::factory()->admin()->create();
    $inactiveBureau = User::factory()->bureauDirection()->inactive()->create();

    $administration = app(AccountAdministration::class);
    $provisioner = app(AccountProvisioner::class);

    expect(fn () => $provisioner->create('Interdit', 'interdit@example.test', Role::Ouvrier, $admin))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $administration->changeRole($target, Role::ChefEquipe, $admin))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $administration->deactivate($target, 'Tentative non autorisée.', $inactiveBureau))
        ->toThrow(RuntimeException::class)
        ->and(fn () => $administration->resetMultiFactor($target, 'Tentative non autorisée.', $admin))
        ->toThrow(RuntimeException::class);

    $administration->changeRole($target, Role::ChefEquipe, $bureau);

    expect($target->fresh()->role)->toBe(Role::ChefEquipe)
        ->and(fn () => $administration->reactivate($inactiveBureau, str_repeat('a', 501), $bureau))
        ->toThrow(RuntimeException::class);
});

it('bootstraps the first Bureau account through the CLI and then closes the door', function () {
    Notification::fake();

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'direction@example.test',
        '--name' => 'Direction C2ME',
        '--confirm' => true,
    ])->assertExitCode(0);

    $bureau = User::query()->where('email', 'direction@example.test')->firstOrFail();

    expect($bureau->role)->toBe(Role::BureauDirection)
        ->and($bureau->activated_at)->toBeNull();

    Notification::assertSentTo($bureau, AccountActivationNotification::class);

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'autre@example.test',
        '--name' => 'Autre Direction',
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'autre@example.test']);
});

it('refuses to bootstrap over an existing non-Bureau account', function () {
    User::factory()->admin()->create(['email' => 'technique@example.test']);

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'technique@example.test',
        '--name' => 'Technique',
        '--confirm' => true,
    ])->assertExitCode(1);
});

it('resets a lost second factor through an authorized CLI action', function () {
    $actor = User::factory()->bureauDirection()->create();
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();
    insertSessionFor($admin, 'session-admin');

    $this->artisan('planning:mfa:reset', [
        'email' => $admin->email,
        '--actor' => $actor->email,
        '--reason' => 'Perte de l’appareil et des codes de secours.',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect($admin->fresh()->app_authentication_secret)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::MfaReset->value,
        'subject_id' => (string) $admin->id,
    ]);
});

it('revokes all sessions during an emergency lockdown without granting access', function () {
    $user = User::factory()->bureauDirection()->create();
    insertSessionFor($user, 'session-emergency');

    $this->artisan('planning:emergency-lockdown', ['--confirm' => true])->assertExitCode(0);

    expect(DB::table('sessions')->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
});

it('configures a fifteen minute session and a single-use password reset token', function () {
    expect(config('session.lifetime'))->toBe(15)
        ->and(config('auth.passwords.users.expire'))->toBe(60);

    $user = User::factory()->bureauDirection()->create();
    $token = Password::createToken($user);

    $status = Password::broker()->reset([
        'email' => $user->email,
        'token' => $token,
        'password' => 'N3w-Passw0rd!x',
        'password_confirmation' => 'N3w-Passw0rd!x',
    ], function (User $user, string $password): void {
        $user->forceFill(['password' => $password])->save();
    });

    expect($status)->toBe(Password::PASSWORD_RESET)
        ->and(Hash::check('N3w-Passw0rd!x', $user->fresh()->password))->toBeTrue();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    $second = Password::broker()->reset([
        'email' => $user->email,
        'token' => $token,
        'password' => 'N3w-Passw0rd!y',
        'password_confirmation' => 'N3w-Passw0rd!y',
    ], fn () => null);

    expect($second)->toBe(Password::INVALID_TOKEN);

    $expired = Password::createToken($user);
    $this->travel(61)->minutes();

    $third = Password::broker()->reset([
        'email' => $user->email,
        'token' => $expired,
        'password' => 'N3w-Passw0rd!z',
        'password_confirmation' => 'N3w-Passw0rd!z',
    ], fn () => null);

    expect($third)->toBe(Password::INVALID_TOKEN);
});

it('never exposes secrets when a user is serialized', function () {
    $user = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $array = $user->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token')
        ->and($array)->not->toHaveKey('app_authentication_secret')
        ->and($array)->not->toHaveKey('app_authentication_recovery_codes')
        ->and(Str::contains($user->toJson(), 'JBSWY3DPEHPK3PXP'))->toBeFalse();
});
