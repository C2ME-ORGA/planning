<?php

namespace Database\Factories;

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use App\Models\CounterMovement;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CounterMovement>
 */
class CounterMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $volume = fake()->numberBetween(60, 480);

        return [
            'employee_id' => Employee::factory(),
            'type' => CounterMovementType::Credit,
            'direction' => null,
            'volume_minutes' => $volume,
            'balance_before_minutes' => 0,
            'balance_after_minutes' => $volume,
            'month' => '2026-11',
            'quarter' => null,
            'payment_date' => null,
            'reason' => null,
            'comment' => fake()->sentence(),
            'actor_id' => User::factory(),
            'source_reference' => null,
            'occurred_at' => CarbonImmutable::parse('2026-11-15 10:00:00', 'Europe/Paris'),
        ];
    }

    public function type(CounterMovementType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    public function direction(CounterAdjustmentDirection $direction): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => $direction,
        ]);
    }

    public function usage(): static
    {
        return $this->type(CounterMovementType::Usage);
    }

    public function payment(): static
    {
        return $this->type(CounterMovementType::Payment);
    }

    public function adjustment(?CounterAdjustmentDirection $direction = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CounterMovementType::Adjustment,
            'direction' => $direction ?? CounterAdjustmentDirection::Credit,
            'reason' => 'Motif d ajustement de test.',
        ]);
    }
}
