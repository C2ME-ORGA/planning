<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\TeamAssignmentService;

function assignmentEmployee(): Employee
{
    return Employee::factory()->create();
}

it('histories primary and temporary assignments without changing the primary team', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $primaryTeam = Team::factory()->create(['name' => 'Équipe A']);
    $temporaryTeam = Team::factory()->create(['name' => 'Équipe B']);
    $service = app(TeamAssignmentService::class);

    $primary = $service->assign(
        $employee,
        $primaryTeam,
        TeamAssignmentKind::Primary,
        '2026-01-01',
        null,
        $actor,
    );
    $temporary = $service->assign(
        $employee,
        $temporaryTeam,
        TeamAssignmentKind::Temporary,
        '2026-02-01',
        null,
        $actor,
    );

    expect($employee->fresh()->currentPrimaryAssignment()?->team_id)->toBe($primaryTeam->id)
        ->and($primary->fresh()->team_id)->toBe($primaryTeam->id)
        ->and($temporary->fresh()->team_id)->toBe($temporaryTeam->id)
        ->and($employee->fresh()->teamAssignments()->count())->toBe(2);

    $service->close($temporary, '2026-03-31', $actor);

    expect($temporary->fresh()->end_date->toDateString())->toBe('2026-03-31')
        ->and($employee->fresh()->currentPrimaryAssignment()?->team_id)->toBe($primaryTeam->id)
        ->and($employee->fresh()->teamAssignments()->count())->toBe(2);
});

it('keeps previous primary assignments when the team changes', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $firstTeam = Team::factory()->create(['name' => 'Équipe A']);
    $secondTeam = Team::factory()->create(['name' => 'Équipe B']);
    $service = app(TeamAssignmentService::class);

    $first = $service->assign($employee, $firstTeam, TeamAssignmentKind::Primary, '2026-01-01', '2026-06-30', $actor);
    $service->assign($employee, $secondTeam, TeamAssignmentKind::Primary, '2026-07-01', null, $actor);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-06-30')
        ->and($employee->fresh()->teamAssignments()->count())->toBe(2)
        ->and($employee->fresh()->currentPrimaryAssignment()?->team_id)->toBe($secondTeam->id);
});

it('refuses assignments outside the employment periods and inverted dates', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
    ]);
    $team = Team::factory()->create();
    $service = app(TeamAssignmentService::class);

    expect(fn () => $service->assign($employee, $team, TeamAssignmentKind::Temporary, '2025-12-01', '2026-02-01', $actor))
        ->toThrow(RuntimeException::class, 'ne couvre');

    expect(fn () => $service->assign($employee, $team, TeamAssignmentKind::Temporary, '2026-06-01', '2026-07-01', $actor))
        ->toThrow(RuntimeException::class, 'ne couvre');

    expect(fn () => $service->assign($employee, $team, TeamAssignmentKind::Temporary, '2026-06-30', '2026-06-29', $actor))
        ->toThrow(RuntimeException::class, 'ne peut pas précéder');
});

it('refuses overlapping assignments of the same kind', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $teamA = Team::factory()->create(['name' => 'Équipe A']);
    $teamB = Team::factory()->create(['name' => 'Équipe B']);
    $service = app(TeamAssignmentService::class);

    $service->assign($employee, $teamA, TeamAssignmentKind::Primary, '2026-01-01', '2026-12-31', $actor);
    expect(fn () => $service->assign($employee, $teamB, TeamAssignmentKind::Primary, '2026-06-01', null, $actor))
        ->toThrow(RuntimeException::class, 'équipe principale');

    $service->assign($employee, $teamA, TeamAssignmentKind::Temporary, '2026-01-01', '2026-12-31', $actor);
    expect(fn () => $service->assign($employee, $teamB, TeamAssignmentKind::Temporary, '2026-06-01', null, $actor))
        ->toThrow(RuntimeException::class, 'affectation temporaire');
});

it('refuses assignment operations to unauthorized roles', function (Role $role) {
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $team = Team::factory()->create();
    $actor = User::factory()->role($role)->create();

    expect(fn () => app(TeamAssignmentService::class)->assign(
        $employee,
        $team,
        TeamAssignmentKind::Primary,
        '2026-01-01',
        null,
        $actor,
    ))->toThrow(RuntimeException::class);
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);

it('audits team assignment creation and closure and keeps the chain valid', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = assignmentEmployee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $team = Team::factory()->create();
    $service = app(TeamAssignmentService::class);

    $assignment = $service->assign($employee, $team, TeamAssignmentKind::Temporary, '2026-01-01', null, $actor);
    $service->close($assignment, '2026-02-28', $actor);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamAssignmentCreated->value,
        'subject_id' => (string) $assignment->id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamAssignmentClosed->value,
        'subject_id' => (string) $assignment->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});
