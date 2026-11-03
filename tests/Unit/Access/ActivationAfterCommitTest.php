<?php

use App\Enums\AuditAction;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Accounts\AccountActivationService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

it('dispatches the activation notification only after the outer transaction commits', function () {
    Notification::fake();

    $user = User::factory()->unactivated()->create();

    DB::beginTransaction();

    try {
        $token = app(AccountActivationService::class)->send($user);

        Notification::assertNothingSent();

        DB::commit();
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
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

it('does not dispatch an activation notification when the transaction rolls back', function () {
    Notification::fake();

    $user = User::factory()->unactivated()->create();

    DB::beginTransaction();

    try {
        app(AccountActivationService::class)->send($user);
    } finally {
        DB::rollBack();
    }

    Notification::assertNothingSent();

    expect(DB::table('account_activations')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', AuditAction::ActivationSent->value)->count())->toBe(0);
});

it('keeps activation persisted and logs safely when mail transport fails after commit', function () {
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
