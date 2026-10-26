<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditHasher;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('writes a verifiable hash chain', function () {
    $audit = app(AuditLogger::class);
    $actor = User::factory()->bureauDirection()->create();

    $audit->record(AuditAction::AccountCreated, $actor, ['role' => 'bureau_direction']);
    $audit->record(AuditAction::EmailChanged, $actor, ['email' => ['old' => 'a@test.fr', 'new' => 'b@test.fr']]);
    $audit->record(AuditAction::SessionsRevoked, $actor, ['sessions_revoked' => 2]);

    $result = $audit->verify();

    expect($result->isValid)->toBeTrue()
        ->and($result->checked)->toBe(3);

    $this->artisan('planning:audit:verify')->assertExitCode(0);
});

it('binds new events to a dedicated audit key distinct from APP_KEY', function () {
    $audit = app(AuditLogger::class);

    $event = $audit->record(AuditAction::AccountCreated, User::factory()->create());

    expect($event->key_id)->toBe(config('planning.audit.key_id'))
        ->and($audit->verify()->isValid)->toBeTrue();

    config(['planning.audit.key' => config('app.key')]);

    expect(fn () => $audit->record(AuditAction::AccountCreated, User::factory()->create()))
        ->toThrow(RuntimeException::class);

    $result = $audit->verify();

    expect($result->isValid)->toBeFalse()
        ->and($result->brokenAt)->toBeNull();

    $this->artisan('planning:audit:verify')->assertExitCode(1);
});

it('refuses to record without a sufficiently strong audit key', function () {
    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => app(AuditLogger::class)->record(AuditAction::AccountCreated, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('keeps historical APP_KEY events verifiable after introducing the dedicated key', function () {
    $hasher = app(AuditHasher::class);
    $legacyFields = [
        'sequence' => 1,
        'occurred_at' => now()->startOfSecond(),
        'actor_id' => null,
        'actor_role' => null,
        'action' => AuditAction::AccountCreated->value,
        'subject_type' => null,
        'subject_id' => null,
        'reason' => null,
        'changes' => null,
        'context' => null,
        'previous_hash' => null,
        'key_id' => null,
    ];

    AuditEvent::query()->create([
        ...$legacyFields,
        'hash' => hash_hmac('sha256', $hasher->payload($legacyFields), (string) config('app.key')),
        'created_at' => now(),
    ]);

    app(AuditLogger::class)->record(AuditAction::LoginSucceeded, null);

    $result = app(AuditLogger::class)->verify();

    expect($result->isValid)->toBeTrue()
        ->and($result->checked)->toBe(2);
});

it('keeps events verifiable after a declared audit key rotation', function () {
    $hasher = app(AuditHasher::class);
    $oldKey = 'old-audit-key-0123456789abcdef0123456789abcdef';
    $newKey = 'new-audit-key-0123456789abcdef0123456789abcdef';

    $oldFields = [
        'sequence' => 1,
        'occurred_at' => now()->startOfSecond(),
        'actor_id' => null,
        'actor_role' => null,
        'action' => AuditAction::AccountCreated->value,
        'subject_type' => null,
        'subject_id' => null,
        'reason' => null,
        'changes' => null,
        'context' => null,
        'previous_hash' => null,
        'key_id' => 'audit-v0',
    ];

    AuditEvent::query()->create([
        ...$oldFields,
        'hash' => hash_hmac('sha256', $hasher->payload($oldFields), $oldKey),
        'created_at' => now(),
    ]);

    config([
        'planning.audit.key_id' => 'audit-v1',
        'planning.audit.key' => $newKey,
        'planning.audit.previous_keys' => ['audit-v0' => $oldKey],
    ]);

    $newEvent = app(AuditLogger::class)->record(AuditAction::LoginSucceeded, null);

    expect($newEvent->key_id)->toBe('audit-v1')
        ->and(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('refuses to verify an event whose audit key is unknown', function () {
    $hasher = app(AuditHasher::class);
    $fields = [
        'sequence' => 1,
        'occurred_at' => now()->startOfSecond(),
        'actor_id' => null,
        'actor_role' => null,
        'action' => AuditAction::AccountCreated->value,
        'subject_type' => null,
        'subject_id' => null,
        'reason' => null,
        'changes' => null,
        'context' => null,
        'previous_hash' => null,
        'key_id' => 'audit-inconnue',
    ];

    AuditEvent::query()->create([
        ...$fields,
        'hash' => hash_hmac('sha256', $hasher->payload($fields), 'some-key-0123456789abcdef0123456789abcdef'),
        'created_at' => now(),
    ]);

    $result = app(AuditLogger::class)->verify();

    expect($result->isValid)->toBeFalse()
        ->and($result->message)->toContain('Aucune clé d’audit valide');
});

it('redacts credentials and confidential details before storage', function () {
    $audit = app(AuditLogger::class);
    $actor = User::factory()->bureauDirection()->create();

    $event = $audit->record(
        AuditAction::AccountCreated,
        $actor,
        [
            'password' => 'SuperSecret!',
            'nested' => [
                'token' => 'abcdef',
                'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
                'medical_note' => 'Donnée de santé',
                'absence_reason' => 'Arrêt maladie',
                'safe' => 'visible',
            ],
        ],
        'Motif opérationnel.',
        $actor,
    );

    $encoded = (string) json_encode($event->changes);

    expect($encoded)->not->toContain('SuperSecret!')
        ->and($encoded)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($encoded)->not->toContain('Donnée de santé')
        ->and($encoded)->not->toContain('Arrêt maladie')
        ->and($event->changes['nested']['safe'])->toBe('visible')
        ->and($event->changes['password'])->toBe('[masqué]');
});

it('bounds the free-text reason stored in the audit log', function () {
    $audit = app(AuditLogger::class);

    $event = $audit->record(AuditAction::AccountCreated, null, [], str_repeat('é', 700));

    expect(mb_strlen((string) $event->reason))->toBe(500)
        ->and($audit->verify()->isValid)->toBeTrue();
});

it('refuses to modify or delete an audit event', function () {
    $event = app(AuditLogger::class)->record(AuditAction::AccountCreated, User::factory()->create());

    expect(fn () => $event->update(['reason' => 'modifié']))->toThrow(LogicException::class);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['reason' => 'modifié']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('audit_events', [
        'id' => $event->id,
        'reason' => null,
    ]);
});

it('detects an altered event through the hash chain', function () {
    $audit = app(AuditLogger::class);
    $last = $audit->record(AuditAction::AccountCreated, User::factory()->create());

    DB::table('audit_events')->insert([
        'sequence' => $last->sequence + 1,
        'occurred_at' => now(),
        'action' => AuditAction::AccountCreated->value,
        'subject_type' => User::class,
        'subject_id' => '999',
        'reason' => 'Événement forgé.',
        'hash' => str_repeat('0', 64),
        'previous_hash' => $last->hash,
        'created_at' => now(),
    ]);

    $result = $audit->verify();

    expect($result->isValid)->toBeFalse()
        ->and($result->brokenAt)->not->toBeNull();

    $this->artisan('planning:audit:verify')->assertExitCode(1);
});

it('records authentication successes and failures without leaking credentials', function () {
    $user = User::factory()->bureauDirection()->create();

    event(new Login('web', $user, false));

    event(new Failed('web', null, [
        'email' => 'Intrus@Example.Test',
        'password' => 'MotDePasseTrèsSecret!',
    ]));

    $success = AuditEvent::query()->where('action', AuditAction::LoginSucceeded->value)->firstOrFail();
    $failure = AuditEvent::query()->where('action', AuditAction::LoginFailed->value)->firstOrFail();

    expect($success->actor_id)->toBe($user->id)
        ->and($success->actor_role)->toBe('bureau_direction')
        ->and($failure->actor_id)->toBeNull()
        ->and($failure->context['email_hash'])->toBe(hash('sha256', 'intrus@example.test'))
        ->and((string) json_encode($failure->context))->not->toContain('MotDePasseTrèsSecret!')
        ->and($failure->context)->not->toHaveKey('password');

    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('gives read-only audit access to the administrator and the Bureau', function () {
    app(AuditLogger::class)->record(AuditAction::LoginSucceeded, User::factory()->create());

    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();

    $this->actingAs($admin)->get('/admin/audit-events')->assertOk()->assertSee('Journal d’audit');
    $this->actingAs($bureau)->get('/admin/audit-events')->assertOk();

    expect($admin->can('create', AuditEvent::class))->toBeFalse()
        ->and($admin->can('update', AuditEvent::query()->firstOrFail()))->toBeFalse()
        ->and($admin->can('delete', AuditEvent::query()->firstOrFail()))->toBeFalse();
});
