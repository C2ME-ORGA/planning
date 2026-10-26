<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\TeamLeadership;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\TeamLeadershipService;
use Illuminate\Support\Facades\DB;

function leaderEmployee(
    Role $role = Role::ChefEquipe,
    bool $active = true,
    ?DateTimeInterface $periodStart = null,
    ?DateTimeInterface $periodEnd = null,
): Employee {
    $user = User::factory()->role($role)->create(['is_active' => $active]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => ($periodStart ?? now()->subYear())->format('Y-m-d'),
        'end_date' => $periodEnd?->format('Y-m-d'),
    ]);

    return $employee;
}

it('refuses a leader without an active account or with an incompatible role', function () {
    $actor = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $service = app(TeamLeadershipService::class);

    $withoutAccount = Employee::factory()->create();
    EmploymentPeriod::factory()->for($withoutAccount)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    expect(fn () => $service->assign($team, $withoutAccount, now()->toDateString(), null, $actor))
        ->toThrow(RuntimeException::class, 'compte applicatif');

    expect(fn () => $service->assign($team, leaderEmployee(Role::ChefEquipe, false), now()->toDateString(), null, $actor))
        ->toThrow(RuntimeException::class, 'actif');

    expect(fn () => $service->assign($team, leaderEmployee(Role::Ouvrier), now()->toDateString(), null, $actor))
        ->toThrow(RuntimeException::class, 'Chef d’équipe ou Conducteur');
});

it('refuses a leadership whose period is not covered by employment', function () {
    $actor = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $employee = leaderEmployee(
        Role::Conducteur,
        true,
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-06-30'),
    );

    expect(fn () => app(TeamLeadershipService::class)->assign(
        $team,
        $employee,
        '2026-06-01',
        null,
        $actor,
    ))->toThrow(RuntimeException::class, 'ne couvre');

    expect(fn () => app(TeamLeadershipService::class)->assign(
        $team,
        $employee,
        '2025-12-01',
        '2026-02-01',
        $actor,
    ))->toThrow(RuntimeException::class, 'ne couvre');
});

it('refuses two overlapping principal responsibilities for the same team', function () {
    $actor = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $service = app(TeamLeadershipService::class);

    $first = leaderEmployee();
    $second = leaderEmployee(Role::Conducteur);

    $service->assign($team, $first, now()->subMonth()->toDateString(), null, $actor);

    expect(fn () => $service->assign($team, $second, now()->toDateString(), null, $actor))
        ->toThrow(RuntimeException::class, 'déjà un responsable');

    expect(TeamLeadership::query()->where('team_id', $team->id)->count())->toBe(1);
});

it('refuses the same employee as principal of two teams on overlapping periods', function () {
    $actor = User::factory()->bureauDirection()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $employee = leaderEmployee();
    $service = app(TeamLeadershipService::class);

    $service->assign($teamA, $employee, now()->subMonth()->toDateString(), null, $actor);

    expect(fn () => $service->assign($teamB, $employee, now()->toDateString(), null, $actor))
        ->toThrow(RuntimeException::class, 'responsable principal');

    expect(TeamLeadership::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('allows the same employee to lead different teams on disjoint or adjacent periods', function () {
    $actor = User::factory()->bureauDirection()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $teamC = Team::factory()->create(['name' => 'Team C']);
    $employee = leaderEmployee();
    $service = app(TeamLeadershipService::class);

    $firstEnd = now()->subMonths(3)->startOfDay();
    $secondStart = now()->subMonths(2)->startOfDay();
    $adjacentStart = $secondStart->copy()->addMonth()->startOfDay();
    $adjacentEnd = $adjacentStart->copy()->addMonth()->subDay()->startOfDay();

    $service->assign(
        $teamA,
        $employee,
        now()->subMonths(6)->startOfDay()->toDateString(),
        $firstEnd->toDateString(),
        $actor,
    );
    $service->assign(
        $teamB,
        $employee,
        $secondStart->toDateString(),
        $secondStart->copy()->addMonth()->subDay()->toDateString(),
        $actor,
    );
    $service->assign(
        $teamC,
        $employee,
        $adjacentStart->toDateString(),
        $adjacentEnd->toDateString(),
        $actor,
    );

    expect(TeamLeadership::query()->where('employee_id', $employee->id)->count())->toBe(3);
});

it('refuses a reassignment that would make the new leader responsible for two teams', function () {
    $actor = User::factory()->bureauDirection()->create();
    $targetTeam = Team::factory()->create(['name' => 'Target Team']);
    $otherTeam = Team::factory()->create(['name' => 'Other Team']);
    $currentLeader = leaderEmployee();
    $newLeader = leaderEmployee(Role::Conducteur);
    $service = app(TeamLeadershipService::class);

    $current = $service->assign($targetTeam, $currentLeader, now()->subMonth()->toDateString(), null, $actor);
    $other = $service->assign($otherTeam, $newLeader, now()->subMonth()->toDateString(), null, $actor);

    expect(fn () => $service->reassign($targetTeam, $newLeader, now()->toDateString(), $actor, 'Test de conflit.'))
        ->toThrow(RuntimeException::class, 'responsable principal');

    expect($current->fresh()->end_date)->toBeNull()
        ->and($current->fresh()->employee_id)->toBe($currentLeader->id)
        ->and($targetTeam->fresh()->currentLeadership()?->employee_id)->toBe($currentLeader->id)
        ->and($other->fresh()->end_date)->toBeNull();
});

it('reassigns a leader explicitly and preserves the previous responsibility', function () {
    $actor = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create(['name' => 'Équipe A']);
    $previous = leaderEmployee();
    $next = leaderEmployee(Role::Conducteur);
    $service = app(TeamLeadershipService::class);

    $current = $service->assign(
        $team,
        $previous,
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $replacement = $service->reassign($team, $next, now()->toDateString(), $actor, 'Changement de responsable.');

    expect($current->fresh()->end_date->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($replacement->employee_id)->toBe($next->id)
        ->and($replacement->end_date)->toBeNull()
        ->and(TeamLeadership::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and($team->fresh()->currentLeadership()?->employee_id)->toBe($next->id);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamLeaderReassigned->value,
        'subject_id' => (string) $replacement->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('serializes reassignment and closure with the employee locks', function () {
    config(['planning.employees.lock_timeout_seconds' => 1]);

    $actor = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create(['name' => 'Locked Team']);
    $currentLeader = leaderEmployee();
    $newLeader = leaderEmployee(Role::Conducteur);
    $service = app(TeamLeadershipService::class);

    $current = $service->assign($team, $currentLeader, now()->subMonth()->toDateString(), null, $actor);

    config(['database.connections.team_lock_probe' => config('database.connections.mysql')]);
    $probe = DB::connection('team_lock_probe');
    $lockName = 'planning.employee.'.$currentLeader->id;

    $acquired = $probe->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
    expect((int) $acquired->acquired)->toBe(1);

    try {
        expect(fn () => $service->reassign($team, $newLeader, now()->toDateString(), $actor, 'Test de verrou.'))
            ->toThrow(RuntimeException::class, 'autre opération');

        expect($current->fresh()->end_date)->toBeNull()
            ->and($team->fresh()->currentLeadership()?->employee_id)->toBe($currentLeader->id);

        expect(fn () => $service->close($current, now()->toDateString(), $actor, 'Test de verrou.'))
            ->toThrow(RuntimeException::class, 'autre opération');

        expect($current->fresh()->end_date)->toBeNull();
    } finally {
        $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        $probe->disconnect();
    }

    $service->close($current->fresh(), now()->toDateString(), $actor, 'Fin de mission.');

    expect($current->fresh()->end_date->toDateString())->toBe(now()->toDateString());
});

it('serializes role removal with the employee lock', function () {
    config(['planning.employees.lock_timeout_seconds' => 1]);

    $bureau = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create(['name' => 'Role Lock Team']);
    $leader = leaderEmployee();
    app(TeamLeadershipService::class)->assign($team, $leader, now()->subMonth()->toDateString(), null, $bureau);

    config(['database.connections.role_lock_probe' => config('database.connections.mysql')]);
    $probe = DB::connection('role_lock_probe');
    $lockName = 'planning.employee.'.$leader->id;
    $acquired = $probe->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);

    expect((int) $acquired->acquired)->toBe(1);

    try {
        expect(fn () => app(AccountAdministration::class)->changeRole(
            $leader->user,
            Role::Ouvrier,
            $bureau,
        ))->toThrow(RuntimeException::class, 'autre opération');

        expect($leader->user->fresh()->role)->toBe(Role::ChefEquipe);
    } finally {
        $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        $probe->disconnect();
    }
});

it('refuses role removal while a responsibility remains active, then allows it after reassignment', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $leader = leaderEmployee();
    $replacement = leaderEmployee(Role::Conducteur);
    $service = app(TeamLeadershipService::class);

    $service->assign($team, $leader, now()->subMonth()->toDateString(), null, $bureau);

    expect(fn () => app(AccountAdministration::class)->changeRole(
        $leader->user,
        Role::Ouvrier,
        $bureau,
    ))->toThrow(RuntimeException::class, 'responsable principal');

    expect($leader->user->fresh()->role)->toBe(Role::ChefEquipe);

    $service->reassign($team, $replacement, now()->toDateString(), $bureau);

    app(AccountAdministration::class)->changeRole($leader->user->fresh(), Role::Ouvrier, $bureau);

    expect($leader->user->fresh()->role)->toBe(Role::Ouvrier);
});

it('allows role removal after an explicit leadership closure', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $leader = leaderEmployee();
    $service = app(TeamLeadershipService::class);

    $leadership = $service->assign($team, $leader, now()->subMonth()->toDateString(), null, $bureau);
    $service->close($leadership, now()->subDay()->toDateString(), $bureau, 'Fin de mission.');

    app(AccountAdministration::class)->changeRole($leader->user->fresh(), Role::Ouvrier, $bureau);

    expect($leader->user->fresh()->role)->toBe(Role::Ouvrier)
        ->and($leadership->fresh()->end_date->toDateString())->toBe(now()->subDay()->toDateString());
});

it('refuses account deactivation and unlinking while a responsibility remains active', function () {
    $bureau = User::factory()->bureauDirection()->create();
    $team = Team::factory()->create();
    $leader = leaderEmployee();
    $replacement = leaderEmployee(Role::Conducteur);
    $service = app(TeamLeadershipService::class);

    $service->assign($team, $leader, now()->subMonth()->toDateString(), null, $bureau);

    expect(fn () => app(AccountAdministration::class)->deactivate(
        $leader->user,
        'Fin de contrat.',
        $bureau,
    ))->toThrow(RuntimeException::class, 'responsable principal');

    expect(fn () => app(EmployeeService::class)->unlinkAccount($leader, $bureau))
        ->toThrow(RuntimeException::class, 'responsable principal');

    $service->reassign($team, $replacement, now()->toDateString(), $bureau);
    app(EmployeeService::class)->unlinkAccount($leader->fresh(), $bureau);

    expect($leader->fresh()->user_id)->toBeNull();
});

it('refuses leadership operations to unauthorized roles', function (Role $role) {
    $team = Team::factory()->create();
    $employee = leaderEmployee();
    $actor = User::factory()->role($role)->create();

    expect(fn () => app(TeamLeadershipService::class)->assign(
        $team,
        $employee,
        now()->toDateString(),
        null,
        $actor,
    ))->toThrow(RuntimeException::class);
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
