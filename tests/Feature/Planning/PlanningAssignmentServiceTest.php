<?php

use App\Enums\AuditAction;
use App\Enums\PlanningHalfDay;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\PlanningAssignment;
use App\Models\PlanningAssignmentExclusion;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Planning\PlanningRoster;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\PlanningFixtures;

it('creates a planning assignment for a site, team, date and half-day', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    $assignment = app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    expect($assignment->site_id)->toBe($site->id)
        ->and($assignment->team_id)->toBe($team->id)
        ->and($assignment->work_date->toDateString())->toBe('2026-11-02')
        ->and($assignment->half_day)->toBe(PlanningHalfDay::Morning);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::PlanningAssignmentCreated->value,
        'subject_id' => (string) $assignment->id,
        'actor_id' => $bureau->id,
    ]);
});

it('rejects invalid dates, archived sites, non-member exclusions and exact duplicates', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $archivedSite = PlanningFixtures::site(['archived_at' => now()]);
    $team = PlanningFixtures::team();
    $member = PlanningFixtures::employee($team);
    $outsider = PlanningFixtures::employee(PlanningFixtures::team());
    $service = app(PlanningAssignmentService::class);

    expect(fn () => $service->create($site, $team, '2026-02-30', PlanningHalfDay::Morning, [], $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create($archivedSite, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$outsider->id],
        $bureau,
    ))->toThrow(ValidationException::class);

    $service->create($site, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    expect(fn () => $service->create($site, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau))
        ->toThrow(ValidationException::class);

    expect(PlanningAssignment::query()->count())->toBe(1)
        ->and(PlanningAssignmentExclusion::query()->count())->toBe(0)
        ->and($member->fresh()->teamAssignments()->count())->toBe(1);
});

it('stores valid individual exclusions without changing the primary team', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $excluded = PlanningFixtures::employee($team);
    $included = PlanningFixtures::employee($team);

    $assignment = app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Afternoon,
        [$excluded->id, $excluded->id],
        $bureau,
    );

    expect($assignment->excludedEmployeeIds())->toBe([$excluded->id])
        ->and(PlanningAssignmentExclusion::query()->count())->toBe(1)
        ->and($excluded->fresh()->currentPrimaryAssignment()?->team_id)->toBe($team->id)
        ->and($excluded->fresh()->teamAssignments()->count())->toBe(1)
        ->and($included->fresh()->teamAssignments()->count())->toBe(1);
});

it('preserves temporary team assignments when planning a team', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $primaryTeam = PlanningFixtures::team();
    $temporaryTeam = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($primaryTeam);

    $temporary = TeamAssignment::factory()->for($employee)->for($temporaryTeam)->temporary()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $primary = $employee->fresh()->currentPrimaryAssignment();

    app(PlanningAssignmentService::class)->create(
        $site,
        $temporaryTeam,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    expect($employee->fresh()->currentPrimaryAssignment()?->team_id)->toBe($primaryTeam->id)
        ->and($primary?->team_id)->toBe($primaryTeam->id)
        ->and($temporary->fresh()->team_id)->toBe($temporaryTeam->id)
        ->and($temporary->fresh()->end_date?->toDateString())->toBe('2026-12-31')
        ->and(TeamAssignment::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('only includes employees covered by an employment period on the planned date', function (): void {
    $team = PlanningFixtures::team();
    $employee = Employee::factory()->create();

    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => '2026-06-30',
    ]);

    TeamAssignment::factory()->for($employee)->for($team)->create([
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);

    $members = app(PlanningRoster::class)->members($team, CarbonImmutable::parse('2026-11-02'));

    expect($members)->toBeEmpty();
});

it('warns about a team overlap without blocking or modifying the existing assignment', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $firstSite = PlanningFixtures::site();
    $secondSite = PlanningFixtures::site();
    $service = app(PlanningAssignmentService::class);

    $first = $service->create($firstSite, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $second = $service->create($secondSite, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $warnings = $service->warningsFor($second, $bureau);

    expect(PlanningAssignment::query()->count())->toBe(2)
        ->and($first->fresh()->site_id)->toBe($firstSite->id)
        ->and($first->fresh()->team_id)->toBe($team->id)
        ->and($first->fresh()->work_date->toDateString())->toBe('2026-11-02')
        ->and($first->fresh()->half_day)->toBe(PlanningHalfDay::Morning)
        ->and($warnings)->not->toBeEmpty()
        ->and($warnings[0]['type'])->toBe('team')
        ->and($warnings[0]['message'])->toContain('déjà affectée');

    $event = AuditEvent::query()
        ->where('action', AuditAction::PlanningAssignmentCreated->value)
        ->where('subject_id', (string) $second->id)
        ->firstOrFail();

    expect($event->context)->not->toBeNull()
        ->and($event->context['overlaps'] ?? [])->not->toBeEmpty()
        ->and($event->context['overlaps'][0])->toHaveKeys([
            'type',
            'assignment_id',
            'site_id',
            'team_id',
        ])
        ->and($event->context['overlaps'][0])->not->toHaveKey('message')
        ->and($event->context['overlaps'][0])->not->toHaveKey('employee_name');
});

it('warns about an employee overlap and resolves it by exclusion', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $siteA = PlanningFixtures::site();
    $siteB = PlanningFixtures::site();
    $teamA = PlanningFixtures::team();
    $teamB = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($teamA);

    TeamAssignment::factory()->for($employee)->for($teamB)->temporary()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $service = app(PlanningAssignmentService::class);
    $service->create($siteA, $teamA, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $second = $service->create($siteB, $teamB, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $warnings = $service->warningsFor($second, $bureau);

    expect($warnings)->not->toBeEmpty()
        ->and(collect($warnings)->pluck('type')->all())->toContain('employee')
        ->and(collect($warnings)->pluck('employee_id')->all())->toContain($employee->id);

    $withoutConflict = $service->update(
        $second,
        $siteB,
        $teamB,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$employee->id],
        $bureau,
    );

    expect($service->warningsFor($withoutConflict, $bureau))->toBeEmpty();
});

it('separates morning and afternoon and adjacent dates', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $site = PlanningFixtures::site();
    $service = app(PlanningAssignmentService::class);

    $morning = $service->create($site, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $afternoon = $service->create($site, $team, '2026-11-02', PlanningHalfDay::Afternoon, [], $bureau);
    $nextDay = $service->create($site, $team, '2026-11-03', PlanningHalfDay::Morning, [], $bureau);

    expect($service->warningsFor($morning, $bureau))->toBeEmpty()
        ->and($service->warningsFor($afternoon, $bureau))->toBeEmpty()
        ->and($service->warningsFor($nextDay, $bureau))->toBeEmpty();
});

it('updates an assignment and audits the change', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $siteA = PlanningFixtures::site();
    $siteB = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create($siteA, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $updated = $service->update(
        $assignment,
        $siteB,
        $team,
        '2026-11-03',
        PlanningHalfDay::Afternoon,
        [$employee->id],
        $bureau,
    );

    expect($updated->site_id)->toBe($siteB->id)
        ->and($updated->work_date->toDateString())->toBe('2026-11-03')
        ->and($updated->half_day)->toBe(PlanningHalfDay::Afternoon)
        ->and($updated->excludedEmployeeIds())->toBe([$employee->id]);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::PlanningAssignmentUpdated->value,
        'subject_id' => (string) $assignment->id,
        'actor_id' => $bureau->id,
    ]);
});

it('refuses planning operations to unauthorized roles and allows reading to planning roles', function (Role $role): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create($site, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $actor = User::factory()->role($role)->create();

    expect($actor->can('view', $assignment))->toBeFalse()
        ->and($actor->can('create', PlanningAssignment::class))->toBeFalse();

    expect(fn () => $service->create($site, $team, '2026-11-02', PlanningHalfDay::Afternoon, [], $actor))
        ->toThrow(AuthorizationException::class);
})->with([
    'administrateur' => Role::Admin,
    'ouvrier' => Role::Ouvrier,
]);

it('allows planning consultation to conducteurs and chefs without write access', function (Role $role): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create($site, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $actor = User::factory()->role($role)->create();

    expect($actor->can('view', $assignment))->toBeTrue()
        ->and($actor->can('create', PlanningAssignment::class))->toBeFalse()
        ->and($actor->can('update', $assignment))->toBeFalse();

    expect(fn () => $service->warningsFor($assignment, $actor))->not->toThrow(Throwable::class);
})->with([
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
]);

it('rolls back the assignment and exclusions when the audit write fails', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$employee->id],
        $bureau,
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(PlanningAssignment::query()->count())->toBe(0)
        ->and(PlanningAssignmentExclusion::query()->count())->toBe(0);

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::PlanningAssignmentCreated->value,
    ]);
});

it('denies physical deletion of planning assignments', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    $assignment = app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    expect($bureau->can('delete', $assignment))->toBeFalse()
        ->and($bureau->can('deleteAny', PlanningAssignment::class))->toBeFalse()
        ->and(fn () => $assignment->delete())->toThrow(LogicException::class);

    expect(PlanningAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});
