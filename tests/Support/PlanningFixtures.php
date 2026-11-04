<?php

namespace Tests\Support;

use App\Enums\SiteStatus;
use App\Enums\TeamAssignmentKind;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Site;
use App\Models\Team;
use App\Models\TeamAssignment;

/**
 * Fixtures minimales et isolées pour les tests du planning.
 */
final class PlanningFixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function site(array $overrides = []): Site
    {
        $client = Client::query()->create([
            'company_name' => 'Client planning '.fake()->unique()->numerify('####'),
            'street' => '1 rue du Test',
            'postal_code' => '85600',
            'city' => 'Montaigu-Vendée',
            'country' => 'France',
            'email' => fake()->unique()->safeEmail(),
        ]);

        return Site::query()->create(array_merge([
            'name' => 'Chantier planning '.fake()->unique()->numerify('####'),
            'address' => '1 rue du Chantier, 85600 Montaigu-Vendée',
            'client_id' => $client->getKey(),
            'status' => SiteStatus::APreparer->value,
            'archived_at' => null,
        ], $overrides));
    }

    public static function team(?string $name = null): Team
    {
        return Team::query()->create([
            'name' => $name ?? 'Équipe planning '.fake()->unique()->numerify('####'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $employeeOverrides
     */
    public static function employee(
        Team $team,
        array $employeeOverrides = [],
        TeamAssignmentKind $kind = TeamAssignmentKind::Primary,
    ): Employee {
        $employee = Employee::factory()->create($employeeOverrides);

        EmploymentPeriod::factory()->for($employee)->create([
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        TeamAssignment::factory()->for($employee)->for($team)->create([
            'kind' => $kind,
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        return $employee;
    }
}
