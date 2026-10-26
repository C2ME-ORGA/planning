<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Exceptions\EmployeeExitBlockedException;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeLifecycleService;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\EmploymentPeriodService;
use App\Services\Employees\TeamAssignmentService;
use App\Services\Employees\TeamLeadershipService;

it('blocks an exit while assignments or responsibilities remain active after the exit date', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    $primaryTeam = Team::factory()->create(['name' => 'Équipe principale']);
    $temporaryTeam = Team::factory()->create(['name' => 'Équipe temporaire']);
    $leadershipTeam = Team::factory()->create(['name' => 'Équipe responsable']);

    $primary = app(TeamAssignmentService::class)->assign(
        $employee,
        $primaryTeam,
        TeamAssignmentKind::Primary,
        now()->subYear()->toDateString(),
        null,
        $actor,
    );
    $temporary = app(TeamAssignmentService::class)->assign(
        $employee,
        $temporaryTeam,
        TeamAssignmentKind::Temporary,
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $leaderUser = User::factory()->chefEquipe()->create();
    app(EmployeeService::class)->linkAccount($employee, $leaderUser, $actor);
    $leadership = app(TeamLeadershipService::class)->assign(
        $leadershipTeam,
        $employee->fresh(),
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $exitDate = now()->toDateString();
    $periodBefore = $employee->fresh()->employmentPeriods()->firstOrFail();

    try {
        app(EmployeeLifecycleService::class)->exit($employee, $exitDate, $actor);
        $this->fail('La sortie aurait dû être bloquée.');
    } catch (EmployeeExitBlockedException $exception) {
        expect($exception->blockers)->toHaveCount(3);

        $joined = implode(' | ', $exception->blockers);
        expect($joined)->toContain('Équipe principale')
            ->and($joined)->toContain('Équipe temporaire')
            ->and($joined)->toContain('Équipe responsable');
    }

    expect($periodBefore->fresh()->end_date)->toBeNull()
        ->and($primary->fresh()->end_date)->toBeNull()
        ->and($temporary->fresh()->end_date)->toBeNull()
        ->and($leadership->fresh()->end_date)->toBeNull();
});

it('accepts an exit after manual closure of compatible assignments and keeps history', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $teamA = Team::factory()->create(['name' => 'Équipe A']);
    $teamB = Team::factory()->create(['name' => 'Équipe B']);
    $teamC = Team::factory()->create(['name' => 'Équipe C']);

    $primary = app(TeamAssignmentService::class)->assign(
        $employee,
        $teamA,
        TeamAssignmentKind::Primary,
        now()->subYear()->toDateString(),
        null,
        $actor,
    );
    $temporary = app(TeamAssignmentService::class)->assign(
        $employee,
        $teamB,
        TeamAssignmentKind::Temporary,
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );
    app(EmployeeService::class)->linkAccount($employee, User::factory()->chefEquipe()->create(), $actor);
    $leadership = app(TeamLeadershipService::class)->assign(
        $teamC,
        $employee->fresh(),
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $exitDate = now()->toDateString();

    app(TeamAssignmentService::class)->close($primary, $exitDate, $actor);
    app(TeamAssignmentService::class)->close($temporary, $exitDate, $actor);
    app(TeamLeadershipService::class)->close($leadership, $exitDate, $actor);

    $period = app(EmployeeLifecycleService::class)->exit($employee, $exitDate, $actor);

    expect($period->end_date->toDateString())->toBe($exitDate)
        ->and($primary->fresh()->start_date->toDateString())->toBe(now()->subYear()->toDateString())
        ->and($primary->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($temporary->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($leadership->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(1);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('allows cancelling a future assignment before exiting without deleting its history', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $team = Team::factory()->create();

    $future = app(TeamAssignmentService::class)->assign(
        $employee,
        $team,
        TeamAssignmentKind::Temporary,
        now()->addDay()->toDateString(),
        now()->addMonth()->toDateString(),
        $actor,
    );

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor))
        ->toThrow(EmployeeExitBlockedException::class);

    app(TeamAssignmentService::class)->cancel($future, 'Annulation avant la sortie du salarié.', $actor);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);

    expect($future->fresh()->cancelled_at)->not->toBeNull()
        ->and($future->fresh()->end_date->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and(TeamAssignment::query()->whereKey($future->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamAssignmentCancelled->value,
        'subject_id' => (string) $future->id,
    ]);
});

it('creates a rehire as a new period after an exit without reactivating the old account', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $inactive = User::factory()->ouvrier()->inactive()->create();
    app(EmployeeService::class)->linkAccount($employee, $inactive, $actor);

    $periods = app(EmploymentPeriodService::class);
    $first = $periods->add($employee, '2026-01-01', null, $actor);

    app(EmployeeLifecycleService::class)->exit($employee, '2026-06-30', $actor);
    $second = $periods->add($employee, '2026-07-01', '2026-12-31', $actor);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-06-30')
        ->and($second->start_date->toDateString())->toBe('2026-07-01')
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(2)
        ->and($inactive->fresh()->isActive())->toBeFalse()
        ->and($inactive->fresh()->role)->toBe(Role::Ouvrier);
});

it('refuses exit operations to unauthorized roles', function (Role $role) {
    $actor = User::factory()->role($role)->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->toDateString(),
        $actor,
    ))->toThrow(RuntimeException::class);
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
