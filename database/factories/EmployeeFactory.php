<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'user_id' => null,
        ];
    }

    public function withAccount(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user?->getKey() ?? User::factory(),
        ]);
    }
}
