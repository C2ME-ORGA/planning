<?php

namespace Database\Seeders;

use App\Enums\TeamAssignmentKind;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Support\DemoData\DemoDatasetEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de démonstration fictif pour la recette du Lot 3 (PC1-7).
 *
 * Le seeder reste séparé de DatabaseSeeder. Il ne crée aucun compte, rôle ou
 * permission et n'appelle pas les services métier : il ne simule donc aucun
 * événement d'audit. Les responsabilités principales ne sont pas générées,
 * car elles exigent un compte actif lié au salarié et un rôle éligible.
 */
class DemoEmployeesAndTeamsSeeder extends Seeder
{
    use WithoutModelEvents;

    public const MARKER = '(démo PC1-53)';

    public int $employeesCreated = 0;
    public int $employeesExisting = 0;
    public int $periodsCreated = 0;
    public int $periodsExisting = 0;
    public int $teamsCreated = 0;
    public int $teamsExisting = 0;
    public int $assignmentsCreated = 0;
    public int $assignmentsExisting = 0;

    public function __construct(private readonly DemoDatasetEnvironment $environment) {}

    public static function employeeCount(): int { return count(self::employees()); }
    public static function teamCount(): int { return count(self::teams()); }

    public function run(): void
    {
        $this->environment->assertSafe();

        DB::transaction(function (): void {
            $today = CarbonImmutable::today();
            $teams = [];

            foreach (self::teams() as $name) {
                $team = Team::query()->firstOrCreate(['name' => $name]);
                $team->wasRecentlyCreated ? $this->teamsCreated++ : $this->teamsExisting++;
                $teams[$name] = $team;
            }

            $employees = [];
            foreach (self::employees() as $entry) {
                $employee = Employee::query()->firstOrCreate(
                    ['first_name' => $entry['first_name'], 'last_name' => $entry['last_name']],
                    ['user_id' => null],
                );
                $employee->wasRecentlyCreated ? $this->employeesCreated++ : $this->employeesExisting++;
                $employees[$entry['key']] = $employee;

                foreach ($entry['periods'] as $period) {
                    $start = $today->addDays($period['start_offset'])->toDateString();
                    $end = $period['end_offset'] === null ? null : $today->addDays($period['end_offset'])->toDateString();
                    $record = $employee->employmentPeriods()->firstOrCreate(
                        ['start_date' => $start],
                        ['end_date' => $end],
                    );
                    $record->wasRecentlyCreated ? $this->periodsCreated++ : $this->periodsExisting++;
                }
            }

            $assignments = [
                ['employee' => 'ouvrier_actif', 'team' => 'Équipe Montage '.self::MARKER, 'kind' => TeamAssignmentKind::Primary, 'start_offset' => -90, 'end_offset' => null],
                ['employee' => 'chef_sans_compte', 'team' => 'Équipe Atelier '.self::MARKER, 'kind' => TeamAssignmentKind::Primary, 'start_offset' => -60, 'end_offset' => null],
                ['employee' => 'ouvrier_actif', 'team' => 'Équipe Atelier '.self::MARKER, 'kind' => TeamAssignmentKind::Temporary, 'start_offset' => -10, 'end_offset' => 10],
                ['employee' => 'reembauche', 'team' => 'Équipe Montage '.self::MARKER, 'kind' => TeamAssignmentKind::Primary, 'start_offset' => -5, 'end_offset' => null],
            ];

            foreach ($assignments as $entry) {
                $employee = $employees[$entry['employee']];
                $team = $teams[$entry['team']];
                $start = $today->addDays($entry['start_offset'])->toDateString();
                $end = $entry['end_offset'] === null ? null : $today->addDays($entry['end_offset'])->toDateString();

                $covered = $employee->employmentPeriods()
                    ->where('start_date', '<=', $start)
                    ->where(static function ($query) use ($end): void {
                        if ($end === null) {
                            $query->whereNull('end_date');
                        } else {
                            $query->whereNull('end_date')->orWhere('end_date', '>=', $end);
                        }
                    })
                    ->exists();

                if (! $covered) {
                    continue;
                }

                $assignment = TeamAssignment::query()->firstOrCreate(
                    ['employee_id' => $employee->getKey(), 'kind' => $entry['kind']->value, 'start_date' => $start],
                    ['team_id' => $team->getKey(), 'end_date' => $end],
                );
                $assignment->wasRecentlyCreated ? $this->assignmentsCreated++ : $this->assignmentsExisting++;
            }
        });
    }

    /** @return array<int, array{key: string, first_name: string, last_name: string, periods: array<int, array{start_offset: int, end_offset: int|null}>}> */
    private static function employees(): array
    {
        return [
            ['key' => 'ouvrier_actif', 'first_name' => 'Alexandre', 'last_name' => 'Martin '.self::MARKER, 'periods' => [['start_offset' => -365, 'end_offset' => null]]],
            ['key' => 'chef_sans_compte', 'first_name' => 'Camille', 'last_name' => 'Bernard '.self::MARKER, 'periods' => [['start_offset' => -240, 'end_offset' => null]]],
            ['key' => 'reembauche', 'first_name' => 'Morgan', 'last_name' => 'Petit '.self::MARKER, 'periods' => [['start_offset' => -700, 'end_offset' => -350], ['start_offset' => -349, 'end_offset' => null]]],
            ['key' => 'sorti', 'first_name' => 'Élodie', 'last_name' => 'Robert '.self::MARKER, 'periods' => [['start_offset' => -500, 'end_offset' => -30]]],
            ['key' => 'sans_compte', 'first_name' => 'Noa', 'last_name' => 'Richard '.self::MARKER, 'periods' => [['start_offset' => -20, 'end_offset' => null]]],
        ];
    }

    /** @return array<int, string> */
    private static function teams(): array
    {
        return ['Équipe Montage '.self::MARKER, 'Équipe Atelier '.self::MARKER];
    }
}
