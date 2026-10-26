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
