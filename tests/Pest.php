<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, you may bind different classes to it.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to access a set of assertions
| that you can use to verify the result of your code.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to expose helper functions
| that can be used across your test suite. You may define them here.
|
*/

function something()
{
    // ..
}

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use App\Models\CounterMovement;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

function pc35Employee(): Employee
{
    return Employee::factory()->create();
}

function pc35User(): User
{
    return User::factory()->role(\App\Enums\Role::BureauDirection)->create();
}

function pc35CreateMovement(
    Employee $employee,
    User $actor,
    array $overrides = [],
): CounterMovement {
    $defaults = [
        'employee_id' => $employee->getKey(),
        'type' => CounterMovementType::Credit,
        'direction' => null,
        'volume_minutes' => 480,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 480,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => CarbonImmutable::parse('2026-11-15 10:00:00', 'Europe/Paris'),
    ];

    return CounterMovement::factory()->create(array_merge($defaults, $overrides));
}

function pc35BalanceService(): \App\Services\Counter\CounterBalanceService
{
    return app(\App\Services\Counter\CounterBalanceService::class);
}
