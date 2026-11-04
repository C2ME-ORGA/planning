<?php

namespace Database\Factories;

use App\Enums\TeamAssignmentKind;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamAssignment>
 */
class TeamAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'employee_id' => Employee::factory(),
            'kind' => TeamAssignmentKind::Primary,
            'start_date' => now()->subYear()->startOfDay(),
            'end_date' => null,
        ];
    }

    public function temporary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => TeamAssignmentKind::Temporary,
        ]);
    }
}
