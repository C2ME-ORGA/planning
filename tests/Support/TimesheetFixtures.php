<?php

namespace Tests\Support;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\TeamLeadership;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Fixtures minimales pour les autorisations et le périmètre des relevés.
 */
final class TimesheetFixtures
{
    public static function user(Role $role): User
    {
        return User::factory()->role($role)->create();
    }

    public static function employee(?User $user = null): Employee
    {
        return Employee::factory()->create([
            'user_id' => $user?->getKey(),
        ]);
    }

    public static function team(?string $name = null): Team
    {
        return Team::factory()->create([
            'name' => $name ?? 'Équipe relevés '.fake()->unique()->numerify('####'),
        ]);
    }

    public static function assign(
        Employee $employee,
        Team $team,
        string $start = '2026-01-01',
        ?string $end = null,
        TeamAssignmentKind $kind = TeamAssignmentKind::Primary,
    ): TeamAssignment {
        return TeamAssignment::factory()->for($employee)->for($team)->create([
            'kind' => $kind,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    public static function lead(
        Employee $leader,
        Team $team,
        string $start = '2026-01-01',
        ?string $end = null,
    ): TeamLeadership {
        return TeamLeadership::factory()->for($leader)->for($team)->create([
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    public static function timesheet(
        Employee $employee,
        string $week = '2026-11-02',
        TimesheetStatus $status = TimesheetStatus::Brouillon,
    ): Timesheet {
        return Timesheet::factory()->for($employee)->forWeekOf($week)->create([
            'status' => $status,
        ]);
    }

    public static function segment(
        Timesheet $timesheet,
        string $start = '2026-11-02 08:00:00',
        string $end = '2026-11-02 12:00:00',
        TimeCategory $category = TimeCategory::TravailEffectif,
    ): TimesheetSegment {
        return TimesheetSegment::factory()->for($timesheet)->create([
            'category' => $category,
            'start_at' => CarbonImmutable::parse($start, 'Europe/Paris')->utc(),
            'end_at' => CarbonImmutable::parse($end, 'Europe/Paris')->utc(),
        ]);
    }

    public static function audit(
        Timesheet $timesheet,
        AuditAction $action,
        User $actor,
        ?string $reason = null,
    ): void {
        app(AuditLogger::class)->record($action, $timesheet, [], $reason, $actor);
    }
}
