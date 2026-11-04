<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Accounts\AccountActivationService;
use App\Services\Accounts\AccountAdministration;
use App\Services\Accounts\AccountProvisioner;
use App\Services\Accounts\SessionRevoker;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

beforeEach(function () {
    // La révocation SQL ne prend en charge que ce pilote.
    config(['session.driver' => 'database']);
});

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

it('sends the activation notification only after commit', function () {
    Notification::fake();

    $user = User::factory()->unactivated()->create();

    DB::beginTransaction();

    try {
        $token = app(AccountActivationService::class)->send($user);

        Notification::assertNothingSent();

        DB::commit();
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 1) {
            DB::rollBack();
        }

        throw $exception;
    }

    Notification::assertSentToOnce($user, AccountActivationNotification::class);

    $event = DB::table('audit_events')
        ->where('action', AuditAction::ActivationSent->value)
        ->first();

    $activation = DB::table('account_activations')
        ->where('user_id', $user->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and((string) json_encode($event))->not->toContain($token)
        ->and($activation)->not->toBeNull()
        ->and($activation->token_hash)->toBe(hash('sha256', $token));
});

it('does not send an activation notification when the activation transaction is rolled back', function () {
    Notification::fake();

    $user = User::factory()->unactivated()->create();

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountActivationService::class)->send($user))
        ->toThrow(RuntimeException::class);

    Notification::assertNothingSent();
    expect(DB::table('account_activations')->where('user_id', $user->id)->count())->toBe(0);
});

it('does not send an activation notification when an enclosing transaction rolls back', function () {
    Notification::fake();

    $user = User::factory()->unactivated()->create();

    DB::beginTransaction();

    try {
        app(AccountActivationService::class)->send($user);
    } finally {
        DB::rollBack();
    }

    Notification::assertNothingSent();
    expect(DB::table('account_activations')->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps the activation and logs without the token when mail transport fails after commit', function () {
    $user = User::factory()->unactivated()->create();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP indisponible.'));
    app()->instance(Dispatcher::class, $dispatcher);

    Log::spy();

    $token = app(AccountActivationService::class)->send($user);

    expect(DB::table('account_activations')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AuditAction::ActivationSent->value)->count())->toBe(1);

    Log::shouldHaveReceived('error')->once()->withArgs(
        function ($message, $context = []) use ($token): bool {
            return ! str_contains((string) $message, $token)
                && ! str_contains((string) json_encode($context), $token);
        },
    );
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

it('uses the validated activation duration of 1440 minutes', function () {
    expect(config('planning.accounts.activation_ttl_minutes'))->toBe(1440);
});

it('keeps an activation link valid at 23 hours 59 minutes 59 seconds', function () {
    Notification::fake();
    $this->freezeSecond();

    $actor = User::factory()->bureauDirection()->create();
    $user = app(AccountProvisioner::class)->create('Limite Haute', 'limite-haute@example.test', Role::Ouvrier, $actor);
    $service = app(AccountActivationService::class);
    $token = $service->send($user, $actor);

    $this->travel(23)->hours();
    $this->travel(59)->minutes();
    $this->travel(59)->seconds();

    expect($service->findUsable($token))->not->toBeNull();
});

it('expires an activation link at and after 24 hours', function () {
    Notification::fake();
    $this->freezeSecond();

    $actor = User::factory()->bureauDirection()->create();
    $user = app(AccountProvisioner::class)->create('Limite Basse', 'limite-basse@example.test', Role::Ouvrier, $actor);
    $service = app(AccountActivationService::class);
    $token = $service->send($user, $actor);

    $this->travel(24)->hours();

    expect($service->findUsable($token))->toBeNull();

    $this->travel(1)->seconds();

    expect($service->findUsable($token))->toBeNull();
});

it('immediately revokes sessions when an account is deactivated', function () {
    config(['session.driver' => 'database']);
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

it('reactivates an account, audits the action and revokes its sessions', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->inactive()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-reactivate');

    app(AccountAdministration::class)->reactivate($target, 'Retour après absence prolongée.', $actor);

    $target->refresh();

    expect($target->is_active)->toBeTrue()
        ->and($target->deactivated_at)->toBeNull()
        ->and($target->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::AccountReactivated->value,
        'subject_id' => (string) $target->id,
        'actor_id' => $actor->id,
        'reason' => 'Retour après absence prolongée.',
    ]);
});

it('changes a role, audits it once and revokes sessions', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-role');

    // UserObserver attribue l'événement RoleChanged à l'acteur authentifié.
    $this->actingAs($actor);

    app(AccountAdministration::class)->changeRole($target, Role::ChefEquipe, $actor);

    $target->refresh();

    $events = DB::table('audit_events')
        ->where('action', AuditAction::RoleChanged->value)
        ->get();

    expect($target->role)->toBe(Role::ChefEquipe)
        ->and($target->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and($events)->toHaveCount(1)
        ->and($events->first()->actor_id)->toBe($actor->id)
        ->and($events->first()->subject_id)->toBe((string) $target->id);
});

it('revokes sessions with a reason and records the revoked count', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-revoke-1');
    insertSessionFor($target, 'session-revoke-2');

    app(AccountAdministration::class)->revokeSessions($target, 'Revue de sécurité périodique.', $actor);

    $target->refresh();

    $event = DB::table('audit_events')
        ->where('action', AuditAction::SessionsRevoked->value)
        ->first();

    expect($target->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and($event)->not->toBeNull()
        ->and(json_decode($event->changes, true)['sessions_revoked'])->toBe(2);
});

it('resets MFA, audits it without observer duplicate and revokes sessions', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->admin()->withMultiFactorAuthentication()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-mfa');

    app(AccountAdministration::class)->resetMultiFactor($target, 'Perte de l’appareil et des codes.', $actor);

    $target->refresh();

    expect($target->app_authentication_secret)->toBeNull()
        ->and($target->app_authentication_recovery_codes)->toBeNull()
        ->and($target->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::MfaReset->value,
        'subject_id' => (string) $target->id,
        'actor_id' => $actor->id,
    ]);

    expect(DB::table('audit_events')->where('action', AuditAction::MfaDisabled->value)->count())->toBe(0);
});

it('rolls back deactivation when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-rollback-deactivate');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountAdministration::class)->deactivate($target, 'Tentative de désactivation.', $actor))
        ->toThrow(RuntimeException::class);

    $target->refresh();

    expect($target->is_active)->toBeTrue()
        ->and($target->remember_token)->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

it('rolls back a role change when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-rollback-role');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountAdministration::class)->changeRole($target, Role::ChefEquipe, $actor))
        ->toThrow(RuntimeException::class);

    $target->refresh();

    expect($target->role)->toBe(Role::Ouvrier)
        ->and($target->remember_token)->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

it('rolls back an MFA reset when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->admin()->withMultiFactorAuthentication()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-rollback-mfa');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountAdministration::class)->resetMultiFactor($target, 'Tentative de réinitialisation.', $actor))
        ->toThrow(RuntimeException::class);

    $target->refresh();

    expect($target->app_authentication_secret)->not->toBeNull()
        ->and($target->remember_token)->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

it('rolls back a reactivation when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->inactive()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-rollback-reactivate');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountAdministration::class)->reactivate($target, 'Tentative de réactivation.', $actor))
        ->toThrow(RuntimeException::class);

    $target->refresh();

    expect($target->is_active)->toBeFalse()
        ->and($target->remember_token)->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

it('rolls back a session revocation when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;
    insertSessionFor($target, 'session-rollback-revoke');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    expect(fn () => app(AccountAdministration::class)->revokeSessions($target, 'Tentative de révocation.', $actor))
        ->toThrow(RuntimeException::class);

    $target->refresh();

    expect($target->remember_token)->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
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

    $this->assertDatabaseHas('system_locks', [
        'name' => 'bootstrap.bureau_direction',
    ]);

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

    $this->assertDatabaseMissing('system_locks', ['name' => 'bootstrap.bureau_direction']);
});

it('refuses bootstrap when the durable lock marker exists without a Bureau account', function () {
    DB::table('system_locks')->insert([
        'name' => 'bootstrap.bureau_direction',
        'created_at' => now(),
    ]);

    expect(fn () => app(AccountProvisioner::class)->bootstrapBureauDirection('Direction', 'direction@example.test'))
        ->toThrow(RuntimeException::class);

    $this->assertDatabaseMissing('users', ['email' => 'direction@example.test']);
});

it('enforces the bootstrap lock with a database unique key', function () {
    $lock = [
        'name' => 'bootstrap.bureau_direction',
        'created_at' => now(),
    ];

    DB::table('system_locks')->insert($lock);

    expect(DB::table('system_locks')->insertOrIgnore($lock))->toBe(0);
});

it('rolls back the bootstrap user and lock when the audit write fails', function () {
    Notification::fake();
    config(['planning.audit.key' => 'trop-court']);

    $this->artisan('planning:bootstrap-bureau', [
        'email' => 'rollback@example.test',
        '--name' => 'Rollback Direction',
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    $this->assertDatabaseMissing('system_locks', ['name' => 'bootstrap.bureau_direction']);
});

it('resets a lost second factor through an authorized CLI action', function () {
    config(['session.driver' => 'database']);
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
    config(['session.driver' => 'database']);
    $user = User::factory()->bureauDirection()->create();
    insertSessionFor($user, 'session-emergency');

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $user->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(DB::table('sessions')->count())->toBe(0);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
});

it('refuses emergency lockdown without explicit confirmation', function () {
    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->ouvrier()->create();
    insertSessionFor($user, 'session-no-confirm');

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage sans confirmation explicite.',
    ])->assertExitCode(1);

    expect(DB::table('sessions')->count())->toBe(1);

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
});

it('refuses emergency lockdown when the intent cannot be audited', function () {
    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->ouvrier()->create();
    insertSessionFor($user, 'session-invalid-audit');
    config(['planning.audit.key' => 'trop-court']);

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage avec configuration d’audit invalide.',
        '--confirm' => true,
    ])->expectsOutputToContain('événement d’intention')
        ->assertExitCode(1);

    expect(DB::table('sessions')->count())->toBe(1);

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
});

it('rotates every remember token during emergency lockdown', function () {
    $users = User::factory()->count(3)->bureauDirection()->create();
    $actor = $users->first();
    $initialTokens = $users->mapWithKeys(
        static fn (User $user): array => [$user->id => $user->remember_token],
    );

    foreach ($users as $index => $user) {
        insertSessionFor($user, 'session-lockdown-'.$index);
    }

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage global planifié de toutes les sessions.',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(DB::table('sessions')->count())->toBe(0);

    foreach ($users as $user) {
        expect($user->fresh()->remember_token)->not->toBe($initialTokens[$user->id]);
    }
});

it('does not revoke any session when the intent audit write fails', function () {
    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionFor($user, 'session-lockdown-rollback');

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Échec d’audit simulé.'));
    app()->instance(AuditLogger::class, $audit);

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $user->email,
        '--reason' => 'Verrouillage dont l’audit d’intention échoue.',
        '--confirm' => true,
    ])->expectsOutputToContain('événement d’intention')
        ->assertExitCode(1);

    expect(DB::table('sessions')->count())->toBe(1)
        ->and($user->fresh()->remember_token)->toBe($initialToken);
});

it('revokes individual and global sessions with the database driver', function () {
    $first = User::factory()->bureauDirection()->create();
    $second = User::factory()->ouvrier()->create();
    insertSessionFor($first, 'database-revoke-individual');
    insertSessionFor($second, 'database-revoke-global');

    $firstToken = $first->remember_token;
    $secondToken = $second->remember_token;

    $individual = app(SessionRevoker::class)->revoke($first);

    expect($individual->deletedSessions)->toBe(1)
        ->and($individual->sessionDriver)->toBe('database')
        ->and($individual->physicalCleanupState()->value)->toBe('completed')
        ->and(DB::table('sessions')->where('user_id', $first->id)->count())->toBe(0)
        ->and($first->fresh()->remember_token)->not->toBe($firstToken)
        ->and(DB::table('sessions')->where('user_id', $second->id)->count())->toBe(1);

    $global = app(SessionRevoker::class)->revokeAll();

    expect($global->deletedSessions)->toBe(1)
        ->and($global->physicalCleanupState()->value)->toBe('completed')
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and($second->fresh()->remember_token)->not->toBe($secondToken);
});

it('treats physical cleanup as not applicable for non-SQL session drivers', function (string $driver) {
    config(['session.driver' => $driver]);

    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionFor($user, 'unsupported-driver-'.$driver);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $revoker = app(SessionRevoker::class);
    $individual = $revoker->revoke($user);
    $global = $revoker->revokeAll();

    // Instantané pris avant les requêtes de contrôle ci-dessous, qui lisent
    // elles-mêmes la table `sessions`.
    $queriesDuringRevocation = $queries;

    expect($individual->physicalCleanupRequired)->toBeFalse()
        ->and($individual->physicalCleanupState()->value)->toBe('not_applicable')
        ->and($global->physicalCleanupRequired)->toBeFalse()
        ->and($global->physicalCleanupState()->value)->toBe('not_applicable')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->remember_token)->not->toBe($initialToken)
        ->and(collect($queriesDuringRevocation)->contains(
            static fn (string $sql): bool => str_contains(strtolower($sql), 'sessions'),
        ))->toBeFalse();
})->with(['redis', 'file']);

it('records a failed physical cleanup when the session table is unusable', function (string $table) {
    config(['session.table' => $table]);

    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionFor($user, 'invalid-session-table-'.$table);

    $result = app(SessionRevoker::class)->revoke($user);

    expect($result->physicalCleanupRequired)->toBeTrue()
        ->and($result->physicalCleanupState()->value)->toBe('failed')
        ->and($result->physicalError)->not->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBe($initialToken)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
})->with(['missing_sessions_table', 'cache']);

it('completes emergency lockdown without SQL cleanup for non-SQL drivers', function (string $driver) {
    config(['session.driver' => $driver]);

    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->ouvrier()->create();
    insertSessionFor($user, 'emergency-incompatible-'.$driver);

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage avec un pilote de session non SQL.',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
})->with(['redis', 'file']);
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
