<?php

use App\Enums\AuditAction;
use App\Enums\PlanningHalfDay;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\PlanningAssignmentExclusion;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentHistory;
use App\Services\Planning\PlanningAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\PlanningFixtures;

it('records before and after values with explicit exclusion changes', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $siteA = PlanningFixtures::site();
    $siteB = PlanningFixtures::site();
    $teamA = PlanningFixtures::team();
    $teamB = PlanningFixtures::team();
    $excluded = PlanningFixtures::employee($teamB);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create(
        $siteA,
        $teamA,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $service->update(
        $assignment,
        $siteB,
        $teamB,
        '2026-11-03',
        PlanningHalfDay::Afternoon,
        [$excluded->id],
        $bureau,
    );

    $event = AuditEvent::query()
        ->where('action', AuditAction::PlanningAssignmentUpdated->value)
        ->where('subject_id', (string) $assignment->id)
        ->firstOrFail();

    expect($event->changes['before'])->toMatchArray([
        'site_id' => $siteA->id,
        'team_id' => $teamA->id,
        'work_date' => '2026-11-02',
        'half_day' => PlanningHalfDay::Morning->value,
        'excluded_employee_ids' => [],
    ])->and($event->changes['after'])->toMatchArray([
        'site_id' => $siteB->id,
        'team_id' => $teamB->id,
        'work_date' => '2026-11-03',
        'half_day' => PlanningHalfDay::Afternoon->value,
        'excluded_employee_ids' => [$excluded->id],
    ])->and($event->changes['exclusions_added'])->toBe([$excluded->id])
        ->and($event->changes['exclusions_removed'])->toBe([])
        ->and($event->changes['changed_fields'])->toContain(
            'site_id',
            'team_id',
            'work_date',
            'half_day',
            'excluded_employee_ids',
        );
});

it('records exclusion removal without storing employee names in the audit', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$employee->id],
        $bureau,
    );

    $service->update(
        $assignment,
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $event = AuditEvent::query()
        ->where('action', AuditAction::PlanningAssignmentUpdated->value)
        ->firstOrFail();

    expect($event->changes['exclusions_removed'])->toBe([$employee->id])
        ->and($event->changes['excluded_employee_ids'])->toBe([])
        ->and(json_encode($event->changes))->not->toContain($employee->fullName())
        ->and(json_encode($event->context))->not->toContain($employee->fullName());
});

it('projects a readable history without exposing technical audit context', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $service->update(
        $assignment,
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$employee->id],
        $bureau,
    );

    $timeline = app(PlanningAssignmentHistory::class)->timeline($assignment->fresh(), $bureau);
    $encoded = json_encode($timeline);
    $details = collect($timeline)->pluck('details')->flatten()->implode(' ');

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['label'])->toBe('Affectation de planning modifiée')
        ->and($timeline[0]['actor_label'])->toBe($bureau->email)
        ->and($timeline[0]['actor_role'])->toBe(Role::BureauDirection->label())
        ->and($details)->toContain($site->name)
        ->and($details)->toContain($employee->fullName())
        ->and($timeline[1]['label'])->toBe('Affectation de planning créée')
        ->and($encoded)->not->toContain('user_agent')
        ->and($encoded)->not->toContain('"ip"')
        ->and($encoded)->not->toContain('"source"');
});

it('allows planning readers to view history and denies other roles', function (): void {
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

    foreach ([Role::BureauDirection, Role::Conducteur, Role::ChefEquipe] as $role) {
        $actor = User::factory()->role($role)->create();

        expect($actor->can('viewHistory', $assignment))->toBeTrue()
            ->and(app(PlanningAssignmentHistory::class)->timeline($assignment, $actor))->not->toBeEmpty();
    }

    foreach ([Role::Admin, Role::Ouvrier] as $role) {
        $actor = User::factory()->role($role)->create();

        expect($actor->can('viewHistory', $assignment))->toBeFalse();

        expect(fn () => app(PlanningAssignmentHistory::class)->timeline($assignment, $actor))
            ->toThrow(AuthorizationException::class);
    }
});

it('rolls back assignment and exclusion changes when the update audit fails', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => $service->update(
        $assignment,
        $site,
        $team,
        '2026-11-03',
        PlanningHalfDay::Afternoon,
        [$employee->id],
        $bureau,
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($assignment->fresh()->work_date->toDateString())->toBe('2026-11-02')
        ->and($assignment->fresh()->half_day)->toBe(PlanningHalfDay::Morning)
        ->and($assignment->fresh()->excludedEmployeeIds())->toBe([])
        ->and(PlanningAssignmentExclusion::query()->count())->toBe(0)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::PlanningAssignmentUpdated->value)
            ->count())->toBe(0);
});
