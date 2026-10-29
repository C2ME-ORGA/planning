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
use Illuminate\Support\Facades\Auth;
use LogicException;

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
        $periodStart = Timesheet::weekFor($week)['start']->toDateString();

        // La base impose un seul relevé par salarié et par semaine : échouer
        // ici rend l'erreur de fixture explicite au lieu d'une violation SQL.
        if (Timesheet::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_start', $periodStart)
            ->exists()) {
            throw new LogicException(sprintf(
                'Un relevé existe déjà pour ce salarié sur la semaine du %s.',
                $periodStart,
            ));
        }

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

    /**
     * Événement sans acteur, comme ceux émis en console ou par un job.
     * Il ne doit jamais suffire à établir la traçabilité d'un relevé.
     *
     * `AuditLogger` complète un acteur absent par l'utilisateur authentifié :
     * l'absence d'authentification est donc vérifiée pour que le test mesure
     * bien un événement non attribué.
     */
    public static function auditWithoutActor(
        Timesheet $timesheet,
        AuditAction $action,
        ?string $reason = null,
    ): void {
        if (Auth::check()) {
            throw new LogicException(
                'Un utilisateur est authentifié : l’événement serait attribué.',
            );
        }

        app(AuditLogger::class)->record($action, $timesheet, [], $reason);
    }
}
