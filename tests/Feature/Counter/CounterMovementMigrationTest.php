<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the counter_movements table with all expected columns', function (): void {
    expect(Schema::hasTable('counter_movements'))->toBeTrue();

    $columns = Schema::getColumnListing('counter_movements');

    expect($columns)->toContain('id')
        ->and($columns)->toContain('employee_id')
        ->and($columns)->toContain('type')
        ->and($columns)->toContain('direction')
        ->and($columns)->toContain('volume_minutes')
        ->and($columns)->toContain('balance_before_minutes')
        ->and($columns)->toContain('balance_after_minutes')
        ->and($columns)->toContain('month')
        ->and($columns)->toContain('quarter')
        ->and($columns)->toContain('payment_date')
        ->and($columns)->toContain('reason')
        ->and($columns)->toContain('comment')
        ->and($columns)->toContain('actor_id')
        ->and($columns)->toContain('source_reference')
        ->and($columns)->toContain('occurred_at')
        ->and($columns)->toContain('created_at')
        ->and($columns)->toContain('updated_at');
});

it('rejects a movement with an invalid type', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'invalid_type',
        'direction' => null,
        'volume_minutes' => 60,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 60,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a movement with a non-positive volume', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'credit',
        'direction' => null,
        'volume_minutes' => 0,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 0,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative balance_before', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'credit',
        'direction' => null,
        'volume_minutes' => 60,
        'balance_before_minutes' => -10,
        'balance_after_minutes' => 50,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an adjustment with an invalid direction', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'adjustment',
        'direction' => 'invalid_direction',
        'volume_minutes' => 60,
        'balance_before_minutes' => 100,
        'balance_after_minutes' => 160,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => 'Motif valide',
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a non-adjustment with a direction set', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'credit',
        'direction' => 'credit',
        'volume_minutes' => 60,
        'balance_before_minutes' => 0,
        'balance_after_minutes' => 60,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an adjustment without a reason', function (): void {
    $employee = Employee::factory()->create();
    $actor = User::factory()->create();

    expect(fn () => DB::table('counter_movements')->insert([
        'employee_id' => $employee->getKey(),
        'type' => 'adjustment',
        'direction' => 'credit',
        'volume_minutes' => 60,
        'balance_before_minutes' => 100,
        'balance_after_minutes' => 160,
        'month' => '2026-11',
        'quarter' => null,
        'payment_date' => null,
        'reason' => null,
        'comment' => null,
        'actor_id' => $actor->getKey(),
        'source_reference' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
