<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamLeadership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamLeadership>
 */
class TeamLeadershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'employee_id' => Employee::factory(),
            'start_date' => now()->subMonth()->startOfDay(),
            'end_date' => null,
        ];
    }
}
