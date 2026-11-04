<?php

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use App\Models\CounterMovement;
use App\Models\Employee;
use App\Models\User;
use App\Services\Counter\CounterBalanceService;
use Carbon\CarbonImmutable;

it('returns zero for an employee without any movement', function (): void {
    $employee = \App\Models\Employee::factory()->create();
    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(0);
});

it('reconstitutes the balance after a credit', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'volume_minutes' => 480,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 480,
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(480);
});

it('reconstitutes the balance after several movements', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 480,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 480,
        'occurred_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'Europe/Paris'),
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Usage,
        'volume_minutes' => 120,
        'balance_before_minutes' => 480,
        'balance_after_minutes' => 360,
        'occurred_at' => CarbonImmutable::parse('2026-11-02 08:00:00', 'Europe/Paris'),
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 240,
        'balance_before_minutes' => 360,
        'balance_after_minutes' => 600,
        'occurred_at' => CarbonImmutable::parse('2026-11-03 08:00:00', 'Europe/Paris'),
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(600);
});

it('applies an adjustment credit positively', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 300,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 300,
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Adjustment,
        'direction' => CounterAdjustmentDirection::Credit,
        'volume_minutes' => 120,
        'balance_before_minutes' => 300,
        'balance_after_minutes' => 420,
        'reason' => 'Correction manuelle.',
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(420);
});

it('applies an adjustment debit negatively', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 300,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 300,
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Adjustment,
        'direction' => CounterAdjustmentDirection::Debit,
        'volume_minutes' => 120,
        'balance_before_minutes' => 300,
        'balance_after_minutes' => 180,
        'reason' => 'Retenue exceptionnelle.',
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(180);
});

it('applies a payment negatively', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 300,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 300,
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Payment,
        'volume_minutes' => 120,
        'balance_before_minutes' => 300,
        'balance_after_minutes' => 180,
        'quarter' => '2026-Q4',
        'payment_date' => '2026-11-15',
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(180);
});

it('computes the balance at a given date', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 480,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 480,
        'occurred_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Paris'),
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Usage,
        'volume_minutes' => 120,
        'balance_before_minutes' => 480,
        'balance_after_minutes' => 360,
        'occurred_at' => CarbonImmutable::parse('2026-11-15 10:00:00', 'Europe/Paris'),
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee, CarbonImmutable::parse('2026-10-31 23:59:59', 'Europe/Paris')))
        ->toBe(480)
        ->and($service->balanceFor($employee, CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Paris')))
        ->toBe(360);
});

it('orders movements deterministically by occurred_at then id', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    $first = pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 60,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 60,
        'occurred_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'Europe/Paris'),
    ]);
    $second = pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 60,
        'balance_before_minutes' => 60,
        'balance_after_minutes' => 120,
        'occurred_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'Europe/Paris'),
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(120)
        ->and($first->fresh()->id < $second->fresh()->id)->toBeTrue();
});

it('carries the balance over across months', function (): void {
    $employee = pc35Employee();
    $actor = pc35User();

    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Credit,
        'volume_minutes' => 480,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 480,
        'month' => '2026-10',
        'occurred_at' => CarbonImmutable::parse('2026-10-31 10:00:00', 'Europe/Paris'),
    ]);
    pc35CreateMovement($employee, $actor, [
        'type' => CounterMovementType::Usage,
        'volume_minutes' => 120,
        'balance_before_minutes' => 480,
        'balance_after_minutes' => 360,
        'month' => '2026-11',
        'occurred_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'Europe/Paris'),
    ]);

    $service = pc35BalanceService();

    expect($service->balanceFor($employee))->toBe(360);
});
