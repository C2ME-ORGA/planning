<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\PermissionException;
use App\Models\User;
use App\Services\Access\PermissionExceptionService;
use App\Services\Accounts\AccountAdministration;
use App\Services\Accounts\SessionRevoker;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

function useDedicatedSessionStore(): void
{
    config([
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

it('revokes only the target user sessions and rotates its persistent token', function () {
    $target = User::factory()->ouvrier()->create();
    $other = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;

    insertSessionRow($target, 'target-1');
    insertSessionRow($target, 'target-2');
    insertSessionRow($other, 'other-1');

    $deleted = app(SessionRevoker::class)->revoke($target);

    expect($deleted)->toBe(2)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($target->fresh()->remember_token)->not->toBe($initialToken)
        ->and($target->fresh()->session_version)->toBe(1);
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

    $deleted = app(SessionRevoker::class)->revoke($target);

    expect($deleted)->toBe(2)
        ->and(DB::table('sessions')->where('user_id', $target->id)->where('id', 'like', 'dedicated-%')->count())->toBe(0)
        ->and(DB::connection('session_store')->table('sessions')->where('id', 'dedicated-other-1')->count())->toBe(1)
        ->and(DB::table('sessions')->where('id', 'default-decoy')->count())->toBe(1);
});

it('revokes every session and rotates every persistent token', function () {
    $first = User::factory()->ouvrier()->create();
    $second = User::factory()->chefEquipe()->create();
    $firstToken = $first->remember_token;
    $secondToken = $second->remember_token;

    insertSessionRow($first, 'all-first-1');
    insertSessionRow($second, 'all-second-1');

    $deleted = app(SessionRevoker::class)->revokeAll();

    expect($deleted)->toBe(2)
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and($first->fresh()->remember_token)->not->toBe($firstToken)
        ->and($second->fresh()->remember_token)->not->toBe($secondToken)
        ->and($first->fresh()->session_version)->toBe(1)
        ->and($second->fresh()->session_version)->toBe(1);
});

it('propagates a session storage failure instead of pretending revocation succeeded', function () {
    config([
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

    $target = User::factory()->ouvrier()->create();
    $initialToken = $target->remember_token;

    expect(fn () => app(SessionRevoker::class)->revoke($target))
        ->toThrow(QueryException::class);

    expect($target->fresh()->remember_token)->toBe($initialToken)
        ->and($target->fresh()->session_version)->toBe(0);
});

it('does not deactivate or audit success when session revocation fails', function () {
    config([
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

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();

    expect(fn () => app(AccountAdministration::class)->deactivate(
        $target,
        'Départ définitif de l’entreprise.',
        $actor,
    ))->toThrow(QueryException::class);

    $target->refresh();

    expect($target->is_active)->toBeTrue()
        ->and($target->deactivated_at)->toBeNull()
        ->and($target->deactivation_reason)->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $target->id,
    ]);
});

it('does not reactivate or audit success when session revocation fails', function () {
    config([
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

    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->inactive()->create();

    expect(fn () => app(AccountAdministration::class)->reactivate(
        $target,
        'Réintégration après vérification.',
        $actor,
    ))->toThrow(QueryException::class);

    $target->refresh();

    expect($target->is_active)->toBeFalse()
        ->and($target->deactivated_at)->not->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountReactivated->value,
        'subject_id' => (string) $target->id,
    ]);
});

it('refuses a pre-revocation session after deactivation then reactivation', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create([
        'email' => 'ancienne.session@example.test',
        'password' => 'Terrain-Passw0rd!',
    ]);

    $this->post('/login', [
        'email' => $target->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'))
        ->assertSessionHas('auth.session_version', 0);

    $this->assertAuthenticatedAs($target, 'web');

    $administration = app(AccountAdministration::class);
    $administration->deactivate($target, 'Suspension temporaire du compte.', $actor);
    $administration->reactivate($target, 'Réintégration après vérification.', $actor);

    $target->refresh();

    expect($target->is_active)->toBeTrue()
        ->and($target->session_version)->toBe(2);

    // La session ouverte avant la révocation porte encore la version 0.
    $this->withSession(['auth.session_version' => 0])
        ->actingAs($target->fresh(), 'web')
        ->get('/planning')
        ->assertRedirect('/login');

    $this->assertGuest('web');

    // Une nouvelle authentification est nécessaire et reçoit la version courante.
    $this->post('/login', [
        'email' => $target->email,
        'password' => 'Terrain-Passw0rd!',
    ])->assertRedirect(route('workspace.home'))
        ->assertSessionHas('auth.session_version', 2);

    $this->assertAuthenticatedAs($target, 'web');
});

it('refuses an old panel session after a revocation', function () {
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    app(SessionRevoker::class)->revoke($admin);

    $this->withSession(['auth.session_version' => 0])
        ->actingAs($admin->fresh())
        ->get('/admin')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
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
