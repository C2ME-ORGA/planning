<?php

use App\Enums\AuditAction;
use App\Models\User;
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

it('uses the dedicated session connection for individual and global revocation', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-individual');
        insertSessionOn('sessions', $user, 'dedicated-individual');

        expect(app(SessionRevoker::class)->revoke($user))->toBe(1)
            ->and(DB::connection('sessions')->table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->not->toBe($initialToken);

        insertSessionOn('sessions', $user, 'dedicated-global');
        $tokenBeforeGlobal = $user->fresh()->remember_token;

        expect(app(SessionRevoker::class)->revokeAll())->toBe(1)
            ->and(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->not->toBe($tokenBeforeGlobal);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('fails when the dedicated session table is absent and leaves the default table untouched', function () {
    $database = createDedicatedSessionDatabase();

    try {
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-with-absent-dedicated-table');

        $revoker = app(SessionRevoker::class);

        expect(fn () => $revoker->revoke($user))->toThrow(RuntimeException::class);
        expect(fn () => $revoker->revokeAll())->toThrow(RuntimeException::class);

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBe($initialToken);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('fails explicitly for an unknown session connection without mutation', function () {
    config(['session.connection' => 'missing_session_connection']);

    $user = User::factory()->bureauDirection()->create();
    $initialToken = $user->remember_token;
    insertSessionOn('mysql', $user, 'default-unknown-connection');

    $revoker = app(SessionRevoker::class);

    expect(fn () => $revoker->revoke($user))->toThrow(RuntimeException::class);
    expect(fn () => $revoker->revokeAll())->toThrow(RuntimeException::class);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->remember_token)->toBe($initialToken);
});

it('fails explicitly when the session connection cannot be reached without mutation', function () {
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
    $caught = null;

    try {
        $revoker->revoke($user);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    } finally {
        DB::purge('unreachable_session');
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('Impossible de vérifier')
        ->and($caught->getMessage())->toContain('unreachable_session')
        ->and($caught->getPrevious())->not->toBeNull();

    expect(fn () => $revoker->revokeAll())->toThrow(RuntimeException::class);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->remember_token)->toBe($initialToken);
});

it('fails when the dedicated session table is incomplete', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable(withUserId: false);
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-incomplete-dedicated-table');

        $revoker = app(SessionRevoker::class);

        expect(fn () => $revoker->revoke($user))->toThrow(RuntimeException::class);
        expect(fn () => $revoker->revokeAll())->toThrow(RuntimeException::class);

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBe($initialToken);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('uses the dedicated session connection during emergency lockdown', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-emergency-dedicated');
        insertSessionOn('sessions', $user, 'dedicated-emergency');

        $this->artisan('planning:emergency-lockdown', ['--confirm' => true])->assertExitCode(0);

        expect(DB::connection('sessions')->table('sessions')->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('fails emergency lockdown for an unknown session connection without audit success', function () {
    config(['session.connection' => 'missing_session_connection']);

    $user = User::factory()->bureauDirection()->create();
    insertSessionOn('mysql', $user, 'default-emergency-unknown');

    expect(Artisan::call('planning:emergency-lockdown', ['--confirm' => true]))->toBe(1);
    $output = Artisan::output();

    expect($output)->toContain('Opération non exécutée')
        ->and($output)->toContain('indisponible ou inconnue');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmergencyLockdown->value,
    ]);
});

it('fails emergency lockdown when the dedicated session table is absent', function () {
    $database = createDedicatedSessionDatabase();

    try {
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        insertSessionOn('mysql', $user, 'default-emergency-absent-table');

        expect(Artisan::call('planning:emergency-lockdown', ['--confirm' => true]))->toBe(1);
        $output = Artisan::output();

        expect($output)->toContain('Opération non exécutée')
            ->and($output)->toContain('absente ou incomplète');

        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('reports a partial revocation when audit fails after dedicated session deletion', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-audit-partial');
        insertSessionOn('sessions', $user, 'dedicated-audit-partial');

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('assertConfigured')->once();
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Échec d’audit simulé.'));
        app()->instance(AuditLogger::class, $audit);

        expect(Artisan::call('planning:emergency-lockdown', ['--confirm' => true]))->toBe(1);
        $output = Artisan::output();

        expect($output)->toContain('Révocation partielle')
            ->and($output)->toContain('remember tokens');

        expect(DB::connection('sessions')->table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBe($initialToken);

        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});

it('reports a partial revocation when remember token rotation fails after dedicated deletion', function () {
    $database = createDedicatedSessionDatabase();

    try {
        createDedicatedSessionsTable();
        config(['session.connection' => 'sessions', 'session.table' => 'sessions']);

        $user = User::factory()->bureauDirection()->create();
        $initialToken = $user->remember_token;
        insertSessionOn('mysql', $user, 'default-token-partial');
        insertSessionOn('sessions', $user, 'dedicated-token-partial');

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'update `users`')) {
                throw new RuntimeException('Échec simulé du renouvellement des remember tokens.');
            }
        });

        expect(Artisan::call('planning:emergency-lockdown', ['--confirm' => true]))->toBe(1);
        $output = Artisan::output();

        expect($output)->toContain('Révocation partielle')
            ->and($output)->toContain('remember tokens');

        expect(DB::connection('sessions')->table('sessions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
            ->and($user->fresh()->remember_token)->toBe($initialToken);

        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::EmergencyLockdown->value,
        ]);
    } finally {
        dropDedicatedSessionDatabase($database);
    }
});
