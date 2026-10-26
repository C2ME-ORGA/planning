<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Exceptions\EmployeeExitBlockedException;
use App\Models\AuditEvent;
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
use Illuminate\Support\Facades\DB;

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

it('deactivates the linked account only when explicitly requested during exit', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    DB::table('sessions')->insert([
        'id' => 'employee-exit-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor, true);

    expect($employee->fresh()->employmentPeriods()->first()->end_date->toDateString())
        ->toBe(now()->toDateString())
        ->and($user->fresh()->isActive())->toBeFalse()
        ->and($user->fresh()->deactivated_at)->not->toBeNull()
        ->and($user->fresh()->deactivation_reason)->toContain('sortie');

    $this->assertDatabaseMissing('sessions', ['id' => 'employee-exit-session']);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $user->id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('keeps the linked account active when deactivation is not requested', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);

    expect($user->fresh()->isActive())->toBeTrue()
        ->and($user->fresh()->deactivated_at)->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $user->id,
    ]);
});

it('exits a salarié without a linked account even when deactivation is requested', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor, true);

    $period = $employee->fresh()->employmentPeriods()->first();

    expect($period->end_date->toDateString())->toBe(now()->toDateString())
        ->and($employee->fresh()->user_id)->toBeNull();

    $event = AuditEvent::query()
        ->where('action', AuditAction::EmployeeExited->value)
        ->where('subject_id', (string) $employee->id)
        ->firstOrFail();

    expect($event->changes['account_linked'])->toBeFalse()
        ->and($event->changes['account_deactivation_requested'])->toBeTrue()
        ->and($event->changes['account_deactivated'])->toBeFalse();
});

it('rolls back the exit when the account deactivation fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    app(EmployeeService::class)->linkAccount($employee, $actor, $actor);

    $periodBefore = $employee->fresh()->employmentPeriods()->firstOrFail();

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->toDateString(),
        $actor,
        true,
    ))->toThrow(RuntimeException::class, 'désactiver');

    expect($periodBefore->fresh()->end_date)->toBeNull()
        ->and($actor->fresh()->isActive())->toBeTrue();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $actor->id,
    ]);
});

it('refuses immediate account deactivation for a future exit', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->addWeek()->toDateString(),
        $actor,
        true,
    ))->toThrow(RuntimeException::class, 'désactivation immédiate');

    expect($user->fresh()->isActive())->toBeTrue()
        ->and($employee->fresh()->employmentPeriods()->first()->end_date)->toBeNull();
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
