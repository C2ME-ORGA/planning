<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SessionCleanupState;
use App\Listeners\AuditAuthenticationEvents;
use App\Models\AuditEvent;
use App\Models\PermissionException;
use App\Models\User;
use App\Services\Access\PermissionExceptionService;
use App\Services\Accounts\AccountAdministration;
use App\Services\Accounts\AuthenticationGeneration;
use App\Services\Accounts\SessionRevocationException;
use App\Services\Accounts\SessionRevoker;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function insertSessionRow(User $user, string $id, ?string $connection = null): void
{
    DB::connection($connection)->table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

function useDatabaseSessionDriver(): void
{
    config([
        'session.driver' => 'database',
        'session.connection' => null,
        'session.table' => 'sessions',
    ]);
}

function useDedicatedSessionStore(): void
{
    config([
        'session.driver' => 'database',
        'database.connections.session_store' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'session.connection' => 'session_store',
        'session.table' => 'sessions',
    ]);

    DB::purge('session_store');

    Schema::connection('session_store')->create('sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
}

function useBrokenDatabaseSessionStore(): void
{
    config([
        'session.driver' => 'database',
        'database.connections.broken_session_store' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'session.connection' => 'broken_session_store',
        'session.table' => 'sessions',
    ]);

    DB::purge('broken_session_store');
}

function useArraySessionDriverForMiddleware(): void
{
    config([
        'session.driver' => 'array',
        'session.connection' => null,
        'session.table' => 'sessions',
    ]);
}

function useFileSessionDriverWithBrokenTable(): void
{
    config([
        'session.driver' => 'file',
        'database.connections.broken_session_store' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'session.connection' => 'broken_session_store',
        'session.table' => 'sessions',
    ]);

    DB::purge('broken_session_store');
}

/**
 * @return array{0: string, 1: string}
 */
function recallerCookieFor(User $user): array
{
    $guard = Auth::guard('web');

    return [
        $guard->getRecallerName(),
        $user->getKey().'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->password),
    ];
}

/**
 * @return array<string, int>
 */
function sessionMarkers(User $user, ?int $generation = null): array
{
    return [
        'auth.session_version' => (int) $user->session_version,
        'auth.global_generation' => $generation ?? app(AuthenticationGeneration::class)->current(),
    ];
}

it('revokes only the target user sessions and rotates its persistent token', function () {
    useDatabaseSessionDriver();

    $target = User::factory()->ouvrier()->create();
    $other = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;

    insertSessionRow($target, 'target-1');
    insertSessionRow($target, 'target-2');
    insertSessionRow($other, 'other-1');

    $result = app(SessionRevoker::class)->revoke($target);

    expect($result->scope)->toBe('user')
        ->and($result->generation)->toBe(1)
        ->and($result->deletedSessions)->toBe(2)
        ->and($result->sessionDriver)->toBe('database')
        ->and($result->physicalCleanupRequired)->toBeTrue()
        ->and($result->physicalError)->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::Completed)
        ->and($result->isPhysicalCleanupComplete())->toBeTrue()
        ->and($result->hasPhysicalCleanupFailure())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($target->fresh()->remember_token)->not->toBe($initialToken)
        ->and($target->fresh()->session_version)->toBe(1);
});

it('keeps individual revocation secure when the file session storage has no SQL table', function () {
    useFileSessionDriverWithBrokenTable();

    $target = User::factory()->conducteur()->create();
    $initialToken = $target->remember_token;

    $result = app(SessionRevoker::class)->revoke($target);

    expect($result->physicalCleanupRequired)->toBeFalse()
        ->and($result->physicalError)->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::NotApplicable)
        ->and($result->isPhysicalCleanupComplete())->toBeFalse()
        ->and($result->hasPhysicalCleanupFailure())->toBeFalse()
        ->and($target->fresh()->remember_token)->not->toBe($initialToken)
        ->and($target->fresh()->session_version)->toBe(1);

    // Une session ouverte avant la révocation logique est refusée.
    $this->actingAs($target->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => app(AuthenticationGeneration::class)->current()])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('deletes sessions on the configured connection without touching the default one', function () {
    useDedicatedSessionStore();

    $target = User::factory()->ouvrier()->create();
    $other = User::factory()->ouvrier()->create();

    insertSessionRow($target, 'dedicated-target-1', 'session_store');
    insertSessionRow($target, 'dedicated-target-2', 'session_store');
    insertSessionRow($other, 'dedicated-other-1', 'session_store');

    // Leurres dans la table homonyme de la connexion par défaut : ils ne
    // doivent jamais être supprimés par une révocation ciblant l'autre base.
    insertSessionRow($target, 'default-decoy');

    $result = app(SessionRevoker::class)->revoke($target);

    expect($result->deletedSessions)->toBe(2)
        ->and($result->physicalError)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $target->id)->where('id', 'like', 'dedicated-%')->count())->toBe(0)
        ->and(DB::connection('session_store')->table('sessions')->where('id', 'dedicated-other-1')->count())->toBe(1)
        ->and(DB::table('sessions')->where('id', 'default-decoy')->count())->toBe(1);
});

it('keeps logical revocation effective when database cleanup fails', function () {
    useBrokenDatabaseSessionStore();

    $target = User::factory()->conducteur()->create();
    $initialToken = $target->remember_token;

    $result = app(SessionRevoker::class)->revoke($target);

    expect($result->physicalCleanupRequired)->toBeTrue()
        ->and($result->physicalError)->not->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::Failed)
        ->and($result->isPhysicalCleanupComplete())->toBeFalse()
        ->and($result->hasPhysicalCleanupFailure())->toBeTrue()
        ->and($target->fresh()->remember_token)->not->toBe($initialToken)
        ->and($target->fresh()->session_version)->toBe(1);

    useArraySessionDriverForMiddleware();

    $this->actingAs($target->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => app(AuthenticationGeneration::class)->current()])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('revokes every session and invalidates every remember token globally', function () {
    useDatabaseSessionDriver();

    $first = User::factory()->conducteur()->create();
    $second = User::factory()->conducteur()->create();
    $oldGeneration = app(AuthenticationGeneration::class)->current();

    insertSessionRow($first, 'all-first-1');
    insertSessionRow($second, 'all-second-1');

    $result = app(SessionRevoker::class)->revokeAll();

    expect($result->scope)->toBe('global')
        ->and($result->generation)->toBe($oldGeneration + 1)
        ->and($result->deletedSessions)->toBe(2)
        ->and($result->physicalError)->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::Completed)
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and($first->fresh()->remember_token)->toBeNull()
        ->and($second->fresh()->remember_token)->toBeNull();

    foreach ([$first, $second] as $user) {
        $this->actingAs($user->fresh())
            ->withSession(['auth.session_version' => 0, 'auth.global_generation' => $oldGeneration])
            ->get('/planning')
            ->assertRedirect('/login');

        $this->assertGuest('web');
    }
});

it('revokes globally with the file driver without needing the sessions table', function () {
    useFileSessionDriverWithBrokenTable();

    $user = User::factory()->conducteur()->create();
    $oldGeneration = app(AuthenticationGeneration::class)->current();

    $result = app(SessionRevoker::class)->revokeAll();

    expect($result->physicalCleanupRequired)->toBeFalse()
        ->and($result->physicalError)->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::NotApplicable)
        ->and($result->isPhysicalCleanupComplete())->toBeFalse()
        ->and($result->hasPhysicalCleanupFailure())->toBeFalse()
        ->and($result->generation)->toBe($oldGeneration + 1)
        ->and($user->fresh()->remember_token)->toBeNull();
});

it('deletes global sessions on the dedicated connection only', function () {
    useDedicatedSessionStore();

    $first = User::factory()->ouvrier()->create();
    $second = User::factory()->ouvrier()->create();

    insertSessionRow($first, 'dedicated-global-1', 'session_store');
    insertSessionRow($second, 'dedicated-global-2', 'session_store');
    insertSessionRow($first, 'default-global-decoy');

    $result = app(SessionRevoker::class)->revokeAll();

    expect($result->deletedSessions)->toBe(2)
        ->and(DB::connection('session_store')->table('sessions')->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'default-global-decoy')->count())->toBe(1);
});

it('keeps global revocation effective when physical cleanup fails', function () {
    useBrokenDatabaseSessionStore();

    $user = User::factory()->conducteur()->create();
    $oldGeneration = app(AuthenticationGeneration::class)->current();

    $result = app(SessionRevoker::class)->revokeAll();

    expect($result->physicalCleanupRequired)->toBeTrue()
        ->and($result->physicalError)->not->toBeNull()
        ->and($result->physicalCleanupState())->toBe(SessionCleanupState::Failed)
        ->and($result->isPhysicalCleanupComplete())->toBeFalse()
        ->and($result->hasPhysicalCleanupFailure())->toBeTrue()
        ->and($result->generation)->toBe($oldGeneration + 1)
        ->and($user->fresh()->remember_token)->toBeNull();

    useArraySessionDriverForMiddleware();

    $this->actingAs($user->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => $oldGeneration])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('records the actor, reason and global generation in the emergency lockdown audit', function () {
    useDatabaseSessionDriver();

    $actor = User::factory()->bureauDirection()->create();
    User::factory()->conducteur()->create();

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(0);

    $requested = AuditEvent::query()
        ->where('action', AuditAction::EmergencyLockdownRequested->value)
        ->firstOrFail();

    $event = AuditEvent::query()
        ->where('action', AuditAction::EmergencyLockdown->value)
        ->firstOrFail();

    expect($requested->actor_id)->toBe($actor->id)
        ->and($requested->reason)->toBe('Verrouillage demandé après incident de sécurité.')
        ->and($event->actor_id)->toBe($actor->id)
        ->and($event->reason)->toBe('Verrouillage demandé après incident de sécurité.')
        ->and($event->changes['global_generation'])->toBe(2)
        ->and($event->changes['fully_completed'])->toBeTrue()
        ->and($event->changes['session_storage'])->toBe('database')
        ->and($event->changes['physical_cleanup_state'])->toBe(SessionCleanupState::Completed->value);
});

it('rejects a lockdown without an eligible actor or a valid reason', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $inactive = User::factory()->bureauDirection()->inactive()->create();
    $admin = User::factory()->admin()->create();

    $cases = [
        ['--confirm' => true],
        [
            '--actor' => $inactive->email,
            '--reason' => 'Verrouillage demandé après incident de sécurité.',
            '--confirm' => true,
        ],
        [
            '--actor' => $admin->email,
            '--reason' => 'Verrouillage demandé après incident de sécurité.',
            '--confirm' => true,
        ],
        [
            '--actor' => $bureau->email,
            '--confirm' => true,
        ],
        [
            '--actor' => $bureau->email,
            '--reason' => 'court',
            '--confirm' => true,
        ],
    ];

    foreach ($cases as $options) {
        $this->artisan('planning:emergency-lockdown', $options)->assertExitCode(1);
    }

    expect(AuditEvent::query()->where('action', AuditAction::EmergencyLockdown->value)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdownRequested->value)->exists())->toBeFalse();
});

it('reports an operational failure but audits an effective logical lockdown when cleanup fails', function () {
    useBrokenDatabaseSessionStore();

    $actor = User::factory()->bureauDirection()->create();
    User::factory()->conducteur()->create();

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(1);

    $event = AuditEvent::query()
        ->where('action', AuditAction::EmergencyLockdown->value)
        ->firstOrFail();

    expect($event->actor_id)->toBe($actor->id)
        ->and($event->changes['fully_completed'])->toBeFalse()
        ->and($event->changes['global_generation'])->toBe(2)
        ->and($event->changes['physical_cleanup_state'])->toBe(SessionCleanupState::Failed->value)
        ->and($event->changes['physical_cleanup_error'])->not->toBeNull();
});

it('accepts a valid remember-me cookie before revocation', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    [$name, $value] = recallerCookieFor($user);

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertOk();

    $this->assertAuthenticatedAs($user, 'web');
});

it('refuses an old remember-me cookie after individual revocation', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    [$name, $value] = recallerCookieFor($user);

    app(SessionRevoker::class)->revoke($user);

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('refuses an old remember-me cookie after global revocation', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    [$name, $value] = recallerCookieFor($user);

    app(SessionRevoker::class)->revokeAll();

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('rejects a remember-me login when its token is rotated during authentication', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    [$name, $value] = recallerCookieFor($user);

    Event::forget(Login::class);
    Event::listen(Login::class, function () use ($user): void {
        User::query()->whereKey($user->getKey())->update(['remember_token' => Str::random(60)]);
    });
    Event::listen(Login::class, [app(AuditAuthenticationEvents::class), 'handleLogin']);

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('rejects a remember-me login when a global revocation occurs during authentication', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    [$name, $value] = recallerCookieFor($user);

    Event::forget(Login::class);
    Event::listen(Login::class, function (): void {
        app(AuthenticationGeneration::class)->revokeAll();
    });
    Event::listen(Login::class, [app(AuditAuthenticationEvents::class), 'handleLogin']);

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('deactivates and audits a failed physical cleanup without lying about security', function () {
    useBrokenDatabaseSessionStore();

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();

    app(AccountAdministration::class)->deactivate(
        $target,
        'Départ définitif de l’entreprise.',
        $actor,
    );

    $target->refresh();

    $event = AuditEvent::query()
        ->where('action', AuditAction::AccountDeactivated->value)
        ->where('subject_id', (string) $target->id)
        ->firstOrFail();

    expect($target->is_active)->toBeFalse()
        ->and($event->changes['session_cleanup_state'])->toBe(SessionCleanupState::Failed->value)
        ->and($event->changes['session_cleanup_complete'])->toBeFalse()
        ->and($event->changes['session_cleanup_failed'])->toBeTrue()
        ->and($event->changes['session_cleanup_error'])->not->toBeNull();
});

it('reactivates and audits a failed physical cleanup without lying about security', function () {
    useBrokenDatabaseSessionStore();

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->inactive()->create();

    app(AccountAdministration::class)->reactivate(
        $target,
        'Réintégration après vérification.',
        $actor,
    );

    $target->refresh();

    $event = AuditEvent::query()
        ->where('action', AuditAction::AccountReactivated->value)
        ->where('subject_id', (string) $target->id)
        ->firstOrFail();

    expect($target->is_active)->toBeTrue()
        ->and($event->changes['session_cleanup_state'])->toBe(SessionCleanupState::Failed->value)
        ->and($event->changes['session_cleanup_complete'])->toBeFalse()
        ->and($event->changes['session_cleanup_failed'])->toBeTrue();
});

it('audits individual revocation with its generation and cleanup result', function () {
    useDatabaseSessionDriver();

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();
    insertSessionRow($target, 'audited-session');

    app(AccountAdministration::class)->revokeSessions(
        $target,
        'Révocation de sécurité demandée par le Bureau.',
        $actor,
    );

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionsRevoked->value)
        ->where('subject_id', (string) $target->id)
        ->firstOrFail();

    expect($event->changes['sessions_revoked'])->toBe(1)
        ->and($event->changes['session_generation'])->toBe(1)
        ->and($event->changes['session_cleanup_state'])->toBe(SessionCleanupState::Completed->value)
        ->and($event->changes['session_cleanup_complete'])->toBeTrue()
        ->and($event->changes['session_cleanup_failed'])->toBeFalse();
});

it('refuses a pre-revocation session after deactivation then reactivation', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create([
        'email' => 'ancienne.session@example.test',
        'password' => 'Terrain-Passw0rd!',
    ]);

    $generation = app(AuthenticationGeneration::class)->current();

    $this->post('/login', [
        'email' => $target->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'))
        ->assertSessionHas('auth.session_version', 0)
        ->assertSessionHas('auth.global_generation', $generation);

    $this->assertAuthenticatedAs($target, 'web');

    $administration = app(AccountAdministration::class);
    $administration->deactivate($target, 'Suspension temporaire du compte.', $actor);
    $administration->reactivate($target, 'Réintégration après vérification.', $actor);

    $target->refresh();

    expect($target->is_active)->toBeTrue()
        ->and($target->session_version)->toBe(2);

    $this->actingAs($target->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => $generation])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');

    $this->post('/login', [
        'email' => $target->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'))
        ->assertSessionHas('auth.session_version', 2)
        ->assertSessionHas('auth.global_generation', $generation);

    $this->assertAuthenticatedAs($target, 'web');
});

it('refuses an old panel session after a revocation', function () {
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    app(SessionRevoker::class)->revoke($admin);

    $this->actingAs($admin->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => app(AuthenticationGeneration::class)->current()])
        ->get('/admin')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('rejects sessions with missing or invalid authentication markers', function (array $markers) {
    $user = User::factory()->conducteur()->create();

    $this->actingAs($user)
        ->withSession($markers)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
})->with([
    'missing global generation' => [['auth.global_generation' => null]],
    'missing user version' => [['auth.session_version' => null]],
    'invalid user version' => [['auth.session_version' => 'invalid']],
    'invalid global generation' => [['auth.global_generation' => 'invalid']],
    'unknown user version' => [['auth.session_version' => 999]],
    'unknown global generation' => [['auth.global_generation' => 999]],
]);

it('rejects a stale Livewire update before component handling', function () {
    $user = User::factory()->conducteur()->create();

    $this->actingAs($user)
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => 0])
        ->post(route('default-livewire.update'), [], ['X-Livewire' => 'true'])
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('rejects a Livewire update for a deactivated account', function () {
    $user = User::factory()->conducteur()->inactive()->create();

    $this->actingAs($user)
        ->post(route('default-livewire.update'), [], ['X-Livewire' => 'true'])
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('keeps generation and remember tokens coherent when the global logical transaction fails', function () {
    $user = User::factory()->conducteur()->create();
    $other = User::factory()->ouvrier()->create();
    $generation = app(AuthenticationGeneration::class)->current();
    $rememberToken = $user->remember_token;
    $otherRememberToken = $other->remember_token;
    $sessionVersion = (int) $user->session_version;

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'users')) {
            throw new RuntimeException('Panne injectée avant la transaction globale.');
        }
    });

    expect(fn () => app(SessionRevoker::class)->revokeAll())
        ->toThrow(SessionRevocationException::class);

    expect(app(AuthenticationGeneration::class)->current())->toBe($generation)
        ->and($user->fresh()->remember_token)->toBe($rememberToken)
        ->and($other->fresh()->remember_token)->toBe($otherRememberToken)
        ->and($user->fresh()->session_version)->toBe($sessionVersion)
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdown->value)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::SessionsRevoked->value)->exists())->toBeFalse();
});

it('audits an individual logical revocation failure without a success event', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();
    $rememberToken = $target->remember_token;
    $sessionVersion = (int) $target->session_version;

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'users')) {
            throw new RuntimeException('Panne injectée avant la révocation individuelle.');
        }
    });

    expect(fn () => app(AccountAdministration::class)->revokeSessions(
        $target,
        'Révocation de sécurité demandée par le Bureau.',
        $actor,
    ))->toThrow(SessionRevocationException::class);

    $target->refresh();
    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRevocationFailed->value)
        ->firstOrFail();

    expect($target->session_version)->toBe($sessionVersion)
        ->and($target->remember_token)->toBe($rememberToken)
        ->and($event->actor_id)->toBe($actor->id)
        ->and($event->reason)->toBe('Révocation de sécurité demandée par le Bureau.')
        ->and($event->changes['scope'])->toBe('user')
        ->and($event->changes['step'])->toBe('logical')
        ->and($event->changes['error_class'])->toBe(RuntimeException::class)
        ->and(AuditEvent::query()->where('action', AuditAction::SessionsRevoked->value)->exists())->toBeFalse();
});

it('rolls back logical revocation when reading session_version fails after the update', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();
    $rememberToken = $target->remember_token;
    $sessionVersion = (int) $target->session_version;

    DB::beforeExecuting(function (string $query): void {
        $sql = strtolower($query);

        if (str_starts_with($sql, 'select')
            && str_contains($sql, 'session_version')
            && str_contains($sql, 'users')) {
            throw new RuntimeException('Panne injectée avant la lecture de session_version.');
        }
    });

    expect(fn () => app(AccountAdministration::class)->revokeSessions(
        $target,
        'Révocation de sécurité demandée par le Bureau.',
        $actor,
    ))->toThrow(SessionRevocationException::class);

    $target->refresh();

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRevocationFailed->value)
        ->firstOrFail();

    expect($target->session_version)->toBe($sessionVersion)
        ->and($target->remember_token)->toBe($rememberToken)
        ->and(AuditEvent::query()->where('action', AuditAction::SessionsRevoked->value)->exists())->toBeFalse()
        ->and($event->changes['scope'])->toBe('user')
        ->and($event->changes['step'])->toBe('logical');
});

it('rolls back logical revocation inside an existing business transaction', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();
    $rememberToken = $target->remember_token;
    $sessionVersion = (int) $target->session_version;

    DB::beforeExecuting(function (string $query): void {
        $sql = strtolower($query);

        if (str_starts_with($sql, 'select')
            && str_contains($sql, 'session_version')
            && str_contains($sql, 'users')) {
            throw new RuntimeException('Panne injectée dans une transaction métier existante.');
        }
    });

    expect(fn () => app(AccountAdministration::class)->deactivate(
        $target,
        'Départ définitif de l’entreprise.',
        $actor,
    ))->toThrow(SessionRevocationException::class);

    $target->refresh();

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRevocationFailed->value)
        ->firstOrFail();

    expect($target->is_active)->toBeTrue()
        ->and($target->deactivated_at)->toBeNull()
        ->and($target->session_version)->toBe($sessionVersion)
        ->and($target->remember_token)->toBe($rememberToken)
        ->and(AuditEvent::query()->where('action', AuditAction::AccountDeactivated->value)->exists())->toBeFalse()
        ->and($event->changes['scope'])->toBe('user')
        ->and($event->changes['step'])->toBe('logical');
});

it('audits a global logical revocation failure and never writes a success event', function () {
    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->conducteur()->create();
    $generation = app(AuthenticationGeneration::class)->current();
    $rememberToken = $user->remember_token;

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'users')) {
            throw new RuntimeException('Panne injectée avant la révocation globale.');
        }
    });

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(1);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRevocationFailed->value)
        ->firstOrFail();

    expect(app(AuthenticationGeneration::class)->current())->toBe($generation)
        ->and($user->fresh()->remember_token)->toBe($rememberToken)
        ->and($event->actor_id)->toBe($actor->id)
        ->and($event->changes['scope'])->toBe('global')
        ->and($event->changes['step'])->toBe('logical')
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdownRequested->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdown->value)->exists())->toBeFalse();
});

it('refuses to start a lockdown when the intent cannot be audited', function () {
    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->conducteur()->create();
    $generation = app(AuthenticationGeneration::class)->current();
    $rememberToken = $user->remember_token;

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower($query), 'insert') && str_contains($query, 'audit_events')) {
            throw new RuntimeException('Journal d’audit indisponible.');
        }
    });

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(app(AuthenticationGeneration::class)->current())->toBe($generation)
        ->and($user->fresh()->remember_token)->toBe($rememberToken)
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdownRequested->value)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdown->value)->exists())->toBeFalse();
});

it('keeps the lockdown effective when the final audit is unavailable', function () {
    $actor = User::factory()->bureauDirection()->create();
    $user = User::factory()->conducteur()->create();
    $generation = app(AuthenticationGeneration::class)->current();
    $auditWrites = 0;

    DB::beforeExecuting(function (string $query) use (&$auditWrites): void {
        if (str_starts_with(strtolower($query), 'insert') && str_contains($query, 'audit_events')) {
            $auditWrites++;

            if ($auditWrites > 1) {
                throw new RuntimeException('Audit final indisponible avant insertion.');
            }
        }
    });

    $this->artisan('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage demandé après incident de sécurité.',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(app(AuthenticationGeneration::class)->current())->toBe($generation + 1)
        ->and($user->fresh()->remember_token)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdownRequested->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', AuditAction::EmergencyLockdown->value)->exists())->toBeFalse();
});

it('supports two successive global revocations without reusing a generation', function () {
    $user = User::factory()->conducteur()->create();
    $initialGeneration = app(AuthenticationGeneration::class)->current();

    $first = app(SessionRevoker::class)->revokeAll();
    $second = app(SessionRevoker::class)->revokeAll();

    expect($first->generation)->toBe($initialGeneration + 1)
        ->and($second->generation)->toBe($initialGeneration + 2)
        ->and($user->fresh()->remember_token)->toBeNull();

    $this->actingAs($user->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => $initialGeneration])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('keeps an individual then a global revocation effective together', function () {
    $user = User::factory()->conducteur()->create();
    $initialGeneration = app(AuthenticationGeneration::class)->current();

    $individual = app(SessionRevoker::class)->revoke($user);
    $global = app(SessionRevoker::class)->revokeAll();

    expect($individual->generation)->toBe(1)
        ->and($global->generation)->toBe($initialGeneration + 1)
        ->and($user->fresh()->session_version)->toBe(1)
        ->and($user->fresh()->remember_token)->toBeNull();

    $this->actingAs($user->fresh())
        ->withSession(['auth.session_version' => 0, 'auth.global_generation' => $initialGeneration])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('rejects a classic login whose request started before a global revocation', function () {
    $user = User::factory()->conducteur()->create([
        'email' => 'global.race@example.test',
        'password' => 'Terrain-Passw0rd!',
    ]);

    Event::forget(Login::class);
    Event::listen(Login::class, function (): void {
        app(AuthenticationGeneration::class)->revokeAll();
    });
    Event::listen(Login::class, [app(AuditAuthenticationEvents::class), 'handleLogin']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'));

    $this->get('/planning')->assertRedirect('/login');
    $this->assertGuest('web');
});

it('rejects a classic login whose request started before an individual revocation', function () {
    $user = User::factory()->conducteur()->create([
        'email' => 'individual.race@example.test',
        'password' => 'Terrain-Passw0rd!',
    ]);

    Event::forget(Login::class);
    Event::listen(Login::class, function () use ($user): void {
        app(SessionRevoker::class)->revoke($user);
    });
    Event::listen(Login::class, [app(AuditAuthenticationEvents::class), 'handleLogin']);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'))
        ->assertSessionHas('auth.session_version', 0);

    $this->actingAs($user->fresh())
        ->withSession([
            'auth.session_version' => 0,
            'auth.global_generation' => app(AuthenticationGeneration::class)->current(),
        ])
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');
});

it('refuses an old remember-me cookie after reactivation', function () {
    $user = User::factory()->conducteur()->create(['password' => 'Terrain-Passw0rd!']);
    $actor = User::factory()->bureauDirection()->create();
    [$name, $value] = recallerCookieFor($user);

    $administration = app(AccountAdministration::class);
    $administration->deactivate($user, 'Suspension temporaire du compte.', $actor);
    $administration->reactivate($user, 'Réintégration après vérification.', $actor);

    $this->withCookie($name, $value)
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect('/planning');

    $this->assertAuthenticatedAs($user->fresh(), 'web');
});

it('fails closed when the global authentication state is absent', function () {
    $user = User::factory()->conducteur()->create();
    $rememberToken = $user->remember_token;

    DB::table('authentication_state')->where('id', 1)->delete();

    expect(fn () => app(AuthenticationGeneration::class)->current())
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(SessionRevoker::class)->revokeAll())
        ->toThrow(SessionRevocationException::class)
        ->and($user->fresh()->remember_token)->toBe($rememberToken);
});

it('rejects an incoherent generation and ignores an extra state row', function () {
    $user = User::factory()->conducteur()->create();
    $rememberToken = $user->remember_token;

    DB::table('authentication_state')->where('id', 1)->update(['global_generation' => 0]);

    expect(fn () => app(AuthenticationGeneration::class)->current())
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(SessionRevoker::class)->revokeAll())
        ->toThrow(SessionRevocationException::class)
        ->and($user->fresh()->remember_token)->toBe($rememberToken);

    DB::table('authentication_state')->where('id', 1)->update(['global_generation' => 1]);
    DB::table('authentication_state')->insert([
        'id' => 2,
        'global_generation' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(AuthenticationGeneration::class)->current())->toBe(1)
        ->and(app(SessionRevoker::class)->revokeAll()->generation)->toBe(2)
        ->and((int) DB::table('authentication_state')->where('id', 2)->value('global_generation'))->toBe(999);
});

it('does not rotate remember tokens when replaying a stale active session', function () {
    $user = User::factory()->conducteur()->create();
    $rememberToken = $user->remember_token;
    $user->forceFill(['session_version' => 1])->saveQuietly();

    $this->actingAs($user)
        ->withSession([
            'auth.session_version' => 0,
            'auth.global_generation' => app(AuthenticationGeneration::class)->current(),
        ])
        ->get('/planning')
        ->assertRedirect('/login');

    expect($user->fresh()->remember_token)->toBe($rememberToken);
    $this->assertGuest('web');
});

it('rotates remember tokens when logging out a deactivated account', function () {
    $user = User::factory()->conducteur()->inactive()->create();
    $rememberToken = $user->remember_token;

    $this->actingAs($user)
        ->get('/planning')
        ->assertRedirect('/login');

    expect($user->fresh()->remember_token)->not->toBe($rememberToken);
    $this->assertGuest('web');
});

it('rolls back and audits a role change when its session revocation fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $rememberToken = $target->remember_token;
    $sessionVersion = (int) $target->session_version;

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'session_version')) {
            throw new RuntimeException('Panne injectée avant la révocation liée au rôle.');
        }
    });

    expect(fn () => app(AccountAdministration::class)->changeRole($target, Role::ChefEquipe, $actor))
        ->toThrow(SessionRevocationException::class);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRevocationFailed->value)
        ->firstOrFail();

    expect($target->fresh()->role)->toBe(Role::Ouvrier)
        ->and($target->fresh()->session_version)->toBe($sessionVersion)
        ->and($target->fresh()->remember_token)->toBe($rememberToken)
        ->and($event->changes['scope'])->toBe('user')
        ->and($event->changes['step'])->toBe('logical')
        ->and(AuditEvent::query()->where('action', AuditAction::RoleChanged->value)->exists())->toBeFalse();
});

it('audits a role change with a failed physical session cleanup', function () {
    useBrokenDatabaseSessionStore();

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();

    app(AccountAdministration::class)->changeRole($target, Role::ChefEquipe, $actor);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionsRevoked->value)
        ->where('subject_id', (string) $target->id)
        ->firstOrFail();

    expect($target->fresh()->role)->toBe(Role::ChefEquipe)
        ->and($event->changes['cause'])->toBe('account.role_changed')
        ->and($event->changes['session_cleanup_state'])->toBe(SessionCleanupState::Failed->value)
        ->and($event->changes['session_cleanup_failed'])->toBeTrue()
        ->and($event->changes['session_cleanup_error'])->not->toBeNull();
});

it('audits the session revocation caused by a role change', function () {
    useDatabaseSessionDriver();

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    insertSessionRow($target, 'role-change-session');

    app(AccountAdministration::class)->changeRole($target, Role::ChefEquipe, $actor);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionsRevoked->value)
        ->where('subject_id', (string) $target->id)
        ->firstOrFail();

    expect($event->changes['cause'])->toBe('account.role_changed')
        ->and($event->changes['role'])->toBe(Role::ChefEquipe->value)
        ->and($event->changes['sessions_revoked'])->toBe(1)
        ->and($event->changes['session_cleanup_state'])->toBe(SessionCleanupState::Completed->value);
});

it('refuses to drop the authentication state during a migration rollback', function () {
    $migration = require database_path('migrations/2026_10_27_000007_create_authentication_state_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(Schema::hasTable('authentication_state'))->toBeTrue();
});

it('checks the security schema preflight', function () {
    $this->artisan('planning:security:install', ['--check' => true])
        ->assertExitCode(0);
});

it('recalculates permissions from the current role and exceptions after reactivation', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $service = app(PermissionExceptionService::class);

    $valid = $service->grant(
        $target,
        Permission::PlanningPropose,
        'Renfort temporaire sur le planning.',
        now()->addDays(10),
        $actor,
    );

    $expired = PermissionException::query()->create([
        'user_id' => $target->id,
        'permission' => Permission::AvailabilityViewPerimeter,
        'reason' => 'Exception expirée créée directement en base.',
        'granted_by_id' => $actor->id,
        'granted_at' => now()->subDays(30),
        'expires_at' => now()->subDay(),
    ]);

    $administration = app(AccountAdministration::class);
    $administration->deactivate($target, 'Suspension temporaire du compte.', $actor);
    $administration->reactivate($target, 'Réintégration après vérification.', $actor);

    $target->refresh();

    expect($target->hasPermission(Permission::PlanningPropose))->toBeTrue()
        ->and($target->hasPermission(Permission::AvailabilityViewPerimeter))->toBeFalse();

    $service->revoke($valid->fresh(), 'Fin du renfort temporaire.', $actor);

    expect($target->fresh()->hasPermission(Permission::PlanningPropose))->toBeFalse()
        ->and($expired->fresh()->isActive())->toBeFalse();
});
