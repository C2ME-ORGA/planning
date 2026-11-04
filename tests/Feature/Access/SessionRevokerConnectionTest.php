<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Accounts\AuthenticationGeneration;
use App\Services\Accounts\SessionRevoker;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['session.driver' => 'database']);
});

/**
 * Crée une seconde base MySQL et une connexion Laravel nommée `sessions`.
 * Le DDL est exécuté hors de la transaction de test Laravel pour ne pas
 * casser RefreshDatabase.
 */
function createDedicatedSessionDatabase(): string
{
    $config = config('database.connections.mysql');
    $database = $config['database'].'_sessions';
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = null;

    config([
        'database.connections.sessions' => array_merge($config, [
            'database' => $database,
        ]),
    ]);
    DB::purge('sessions');

    return $database;
}

function dropDedicatedSessionDatabase(string $database): void
{
    DB::purge('sessions');

    $config = config('database.connections.mysql');
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']}",
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo = null;

    config(['database.connections.sessions' => null]);
    DB::purge('sessions');
}

function createDedicatedSessionsTable(bool $withUserId = true): void
{
    Schema::connection('sessions')->create('sessions', function (Blueprint $table) use ($withUserId): void {
        $table->string('id')->primary();

        if ($withUserId) {
            $table->foreignId('user_id')->nullable()->index();
        }

        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->text('payload')->nullable();
        $table->integer('last_activity')->index();
    });
}

function insertSessionOn(string $connection, User $user, string $id): void
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

function lockdownActor(): User
{
    return User::factory()->bureauDirection()->create();
}

it('uses the dedicated session connection for individual and global revocation', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-individual');
        insertSessionOn('sessions', $user, 'dedicated-individual');

        $individual = app(SessionRevoker::class)->revoke($user);

        expect($individual->deletedSessions)->toBe(1)
            ->and($individual->physicalCleanupState()->value)->toBe('completed')
            ->and(DB::connection('sessions')->table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->not->toBe($initialToken);

        insertSessionOn('sessions', $user, 'dedicated-global');

        $global = app(SessionRevoker::class)->revokeAll();

        expect($global->deletedSessions)->toBe(1)
            ->and(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBeNull();
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('keeps logical revocation when the dedicated session table is absent', function () {
    $database = createDedicatedSessionDatabase();

    try {
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-with-absent-dedicated-table');

        $revoker = app(SessionRevoker::class);

        $individual = $revoker->revoke($user);

        expect($individual->physicalError)->not->toBeNull()
            ->and($individual->physicalCleanupState()->value)->toBe('failed')
            ->and($individual->deletedSessions)->toBe(0)
            ->and($user->fresh()->remember_token)->not->toBe($initialToken)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $tokenAfterIndividual = $user->fresh()->remember_token;
        $global = $revoker->revokeAll();

        expect($global->physicalError)->not->toBeNull()
            ->and($global->physicalCleanupState()->value)->toBe('failed')
            ->and($user->fresh()->remember_token)->toBeNull()
            ->and($tokenAfterIndividual)->not->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('keeps logical revocation for an unknown session connection', function () {
    config(['session.connection' => 'missing_session_connection']);

    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionOn('mysql', $user, 'default-unknown-connection');

    $revoker = app(SessionRevoker::class);

    $individual = $revoker->revoke($user);
    $global = $revoker->revokeAll();

    expect($individual->physicalError)->not->toBeNull()
        ->and($global->physicalError)->not->toBeNull()
        ->and($user->fresh()->remember_token)->toBeNull()
        ->and($initialToken)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps logical revocation when the session connection cannot be reached', function () {
    $base = config('database.connections.mysql');

    config([
        'database.connections.unreachable_session' => array_merge($base, [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => $base['database'],
        ]),
        'session.connection' => 'unreachable_session',
    ]);
    DB::purge('unreachable_session');

    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionOn('mysql', $user, 'default-unreachable-connection');

    $revoker = app(SessionRevoker::class);

    try {
        $individual = $revoker->revoke($user);
        $global = $revoker->revokeAll();
    } finally {
        DB::purge('unreachable_session');
    }

    expect($individual->physicalError)->not->toBeNull()
        ->and($global->physicalError)->not->toBeNull()
        ->and($user->fresh()->remember_token)->toBeNull()
        ->and($initialToken)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps logical revocation when the dedicated session table is incomplete', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable(withUserId: false);
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-incomplete-dedicated-table');

        $revoker = app(SessionRevoker::class);

        $individual = $revoker->revoke($user);
        $global = $revoker->revokeAll();

        expect($individual->physicalError)->not->toBeNull()
            ->and($individual->physicalCleanupState()->value)->toBe('failed')
            ->and($global->physicalError)->toBeNull()
            ->and($global->deletedSessions)->toBe(0)
            ->and($user->fresh()->remember_token)->toBeNull()
            ->and($initialToken)->not->toBeNull()
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('uses the dedicated session connection during emergency lockdown', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-emergency-dedicated');
        insertSessionOn('sessions', $user, 'dedicated-emergency');

        $this->artisan('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ])->assertExitCode(0);

        expect(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('records a partial lockdown when the session connection is unknown', function () {
    config(['session.connection' => 'missing_session_connection']);

    $actor = lockdownActor();
    $user = User::factory()->bureauDirection()->create();
    insertSessionOn('mysql', $user, 'default-emergency-unknown');

    expect(Artisan::call('planning:emergency-lockdown', [
        '--actor' => $actor->email,
        '--reason' => 'Verrouillage de test après incident de sécurité.',
        '--confirm' => true,
    ]))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('nettoyage physique des sessions a échoué')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $event = AuditEvent::query()
        ->where('action', AuditAction::EmergencyLockdown->value)
        ->firstOrFail();

    expect($event->changes['physical_cleanup_state'])->toBe('failed')
        ->and($event->changes['fully_completed'])->toBeFalse();
});

it('records a partial lockdown when the dedicated session table is absent', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-emergency-absent-table');

        expect(Artisan::call('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ]))->toBe(1);

        $output = Artisan::output();

        expect($output)->toContain('nettoyage physique des sessions a échoué')
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $event = AuditEvent::query()
            ->where('action', AuditAction::EmergencyLockdown->value)
            ->firstOrFail();

        expect($event->changes['physical_cleanup_state'])->toBe('failed');
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('reports audit unavailability after a successful dedicated revocation', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-audit-partial');
        insertSessionOn('sessions', $user, 'dedicated-audit-partial');
        insertSessionOn('sessions', $user, 'dedicated-audit-partial-2');

        $real = app(AuditLogger::class);
        $calls = 0;

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->twice()->andReturnUsing(
            function (...$arguments) use ($real, &$calls): AuditEvent {
                $calls++;

                if ($calls > 1) {
                    throw new RuntimeException('Échec d’audit final simulé.');
                }

                return $real->record(...$arguments);
            },
        );
        app()->instance(AuditLogger::class, $audit);

        expect(Artisan::call('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ]))->toBe(1);

        $output = Artisan::output();

        expect($output)->toContain('audit final est indisponible')
            ->and(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBeNull();

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::EmergencyLockdownRequested->value,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('reports audit unavailability with zero dedicated sessions without inventing a count', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-audit-zero');

        $real = app(AuditLogger::class);
        $calls = 0;

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->twice()->andReturnUsing(
            function (...$arguments) use ($real, &$calls): AuditEvent {
                $calls++;

                if ($calls > 1) {
                    throw new RuntimeException('Échec d’audit final simulé.');
                }

                return $real->record(...$arguments);
            },
        );
        app()->instance(AuditLogger::class, $audit);

        expect(Artisan::call('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ]))->toBe(1);

        $output = Artisan::output();

        expect($output)->toContain('audit final est indisponible')
            ->and(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBeNull();
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('reports the exact deleted count when the final audit is unavailable', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-audit-multiple');
        insertSessionOn('sessions', $user, 'dedicated-audit-multiple-1');
        insertSessionOn('sessions', $user, 'dedicated-audit-multiple-2');

        $real = app(AuditLogger::class);
        $calls = 0;

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->twice()->andReturnUsing(
            function (...$arguments) use ($real, &$calls): AuditEvent {
                $calls++;

                if ($calls > 1) {
                    throw new RuntimeException('Échec d’audit final simulé.');
                }

                return $real->record(...$arguments);
            },
        );
        app()->instance(AuditLogger::class, $audit);

        expect(Artisan::call('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ]))->toBe(1);

        expect(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBeNull();
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('prevents physical cleanup when remember token rotation fails', function () {
    $database = createDedicatedSessionDatabase();
    $actor = lockdownActor();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-token-partial');
        insertSessionOn('sessions', $user, 'dedicated-token-partial');
        $generation = app(AuthenticationGeneration::class)->current();

        DB::beforeExecuting(function (string $query): void {
            if (str_starts_with(strtolower($query), 'update')
                && str_contains($query, 'users')
                && str_contains($query, 'remember_token')) {
                throw new RuntimeException('Échec simulé du renouvellement des remember tokens.');
            }
        });

        expect(Artisan::call('planning:emergency-lockdown', [
            '--actor' => $actor->email,
            '--reason' => 'Verrouillage de test après incident de sécurité.',
            '--confirm' => true,
        ]))->toBe(1);

        $output = Artisan::output();

        expect($output)->toContain('révocation logique des sessions a échoué')
            ->and(app(AuthenticationGeneration::class)->current())->toBe($generation)
            ->and($user->fresh()->remember_token)->toBe($initialToken)
            ->and(DB::connection('sessions')->table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::SessionRevocationFailed->value,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});
