<?php

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use App\Models\CounterMovement;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;

it('rejects any update attempt on an existing movement', function (): void {
    $movement = CounterMovement::factory()->create();

    expect(fn () => $movement->update(['volume_minutes' => 999]))
        ->toThrow(LogicException::class);
});

it('rejects any delete attempt on an existing movement', function (): void {
    $movement = CounterMovement::factory()->create();

    expect(fn () => $movement->delete())
        ->toThrow(LogicException::class);
});

it('casts type and direction to their enums', function (): void {
    $movement = CounterMovement::factory()->create([
        'type' => CounterMovementType::Adjustment,
        'direction' => CounterAdjustmentDirection::Debit,
        'reason' => 'Motif de test.',
    ]);

    $movement->refresh();

    expect($movement->type)->toBe(CounterMovementType::Adjustment)
        ->and($movement->direction)->toBe(CounterAdjustmentDirection::Debit);
});

it('defaults direction to null for non-adjustment types', function (): void {
    $movement = CounterMovement::factory()->create([
        'type' => CounterMovementType::Credit,
        'direction' => null,
    ]);

    expect($movement->direction)->toBeNull();
});

it('belongs to an employee and an actor', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    $movement = CounterMovement::factory()->create([
        'employee_id' => $employee->getKey(),
        'actor_id' => $actor->getKey(),
    ]);

    expect($movement->employee->is($employee))->toBeTrue()
        ->and($movement->actor->is($actor))->toBeTrue();
});

it('stores occurred_at as a datetime', function (): void {
    $occurredAt = CarbonImmutable::parse('2026-11-15 10:00:00', 'Europe/Paris');

    $movement = CounterMovement::factory()->create([
        'occurred_at' => $occurredAt,
    ]);

    $movement->refresh();

    expect($movement->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-11-15 10:00:00');
});

it('accepts a nullable comment', function (): void {
    $movement = CounterMovement::factory()->create(['comment' => null]);

    expect($movement->comment)->toBeNull();
});

it('accepts a nullable source_reference', function (): void {
    $movement = CounterMovement::factory()->create(['source_reference' => null]);

    expect($movement->source_reference)->toBeNull();
});
