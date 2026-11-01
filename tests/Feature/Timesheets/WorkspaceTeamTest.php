<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Livewire\Timesheets\TimesheetDetail;
use App\Livewire\Timesheets\TimesheetList;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetPerimeter;
use App\Services\Timesheets\TimesheetQuery;
use Livewire\Livewire;
use Tests\Support\TimesheetFixtures;

/**
 * @return array{0: User, 1: Employee, 2: User, 3: Employee}
 */
function workspaceTeamContext(): array
{
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $chef = TimesheetFixtures::employee($chefUser);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $team = TimesheetFixtures::team();
    TimesheetFixtures::assign($owner, $team);
    TimesheetFixtures::lead($chef, $team);

    return [$chefUser, $chef, $ownerUser, $owner];
}

function workspaceTeamTimesheet(
    Employee $employee,
    User $owner,
    User $chef,
    string $week,
    TimesheetStatus $status = TimesheetStatus::AControler,
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet($employee, week: $week, status: $status);
    TimesheetFixtures::segment($timesheet, start: "{$week} 08:00:00", end: "{$week} 12:00:00");
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);

    if ($status === TimesheetStatus::PretePourValidation) {
        TimesheetFixtures::audit(
            $timesheet,
            AuditAction::TimesheetReadyForValidation,
            $chef,
            'Contrôle satisfaisant.',
        );
    }

    return $timesheet;
}

it('shows only the timesheets of the team perimeter', function (): void {
    [$chefUser, , $ownerUser, $owner] = workspaceTeamContext();
    $outsiderUser = TimesheetFixtures::user(Role::Ouvrier);
    $outsider = TimesheetFixtures::employee($outsiderUser);

    workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-02');
    workspaceTeamTimesheet($outsider, $outsiderUser, $chefUser, '2026-11-02');
    $visible = workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-09');

    $this->actingAs($chefUser)
        ->get('/espace')
        ->assertRedirect(route('workspace.team.dashboard'));

    $this->actingAs($chefUser)
        ->get('/espace/equipe')
        ->assertOk()
        ->assertSee('Mon équipe')
        ->assertSee($owner->last_name)
        ->assertDontSee($outsider->last_name);

    $this->actingAs($chefUser)
        ->get('/espace/releves')
        ->assertOk()
        ->assertSee($owner->last_name)
        ->assertDontSee($outsider->last_name);

    $this->actingAs($chefUser)
        ->get(route('workspace.timesheets.show', $visible))
        ->assertOk()
        ->assertSee($owner->last_name);

    $this->actingAs($chefUser)
        ->get(route('workspace.timesheets.queue', ['queue' => 'a-controler']))
        ->assertOk()
        ->assertSee($owner->last_name);

    $this->actingAs($chefUser)
        ->get(route('workspace.timesheets.queue', ['queue' => 'chevauchements']))
        ->assertForbidden();

    $this->actingAs($chefUser)
        ->get('/espace/bureau')
        ->assertForbidden();
});

it('refuses access to a timesheet outside the perimeter by route and Livewire', function (): void {
    [$chefUser, , $ownerUser, $owner] = workspaceTeamContext();
    $outsider = TimesheetFixtures::employee(TimesheetFixtures::user(Role::Ouvrier));
    $outside = workspaceTeamTimesheet($outsider, $ownerUser, $chefUser, '2026-11-02');
    $inside = workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-09');

    $this->actingAs($chefUser)
        ->get(route('workspace.timesheets.show', $outside))
        ->assertForbidden();

    $this->actingAs($chefUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $outside])
        ->assertForbidden();

    Livewire::test(TimesheetDetail::class, ['timesheet' => $inside])
        ->assertOk();
});

it('returns a timesheet for correction with a mandatory reason', function (): void {
    [$chefUser, , $ownerUser, $owner] = workspaceTeamContext();
    $timesheet = workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-02');

    $this->actingAs($chefUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('correctionReason', 'Horaires à préciser pour le chantier.')
        ->call('requestCorrection')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::ACorriger)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetReturnedForCorrection->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $chefUser->getKey())
            ->exists())->toBeTrue();
});

it('requires a reason before marking a timesheet ready', function (): void {
    [$chefUser, , $ownerUser, $owner] = workspaceTeamContext();
    $timesheet = workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-02');

    $this->actingAs($chefUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('markReadyForValidation')
        ->assertHasErrors('readyReason');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('readyReason', 'Contrôle du relevé satisfaisant.')
        ->call('markReadyForValidation')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('cannot validate or review overlaps, even by calling the Livewire actions', function (): void {
    [$chefUser, , $ownerUser, $owner] = workspaceTeamContext();
    $ready = workspaceTeamTimesheet(
        $owner,
        $ownerUser,
        $chefUser,
        '2026-11-02',
        TimesheetStatus::PretePourValidation,
    );
    $overlapping = workspaceTeamTimesheet($owner, $ownerUser, $chefUser, '2026-11-09');
    $second = TimesheetFixtures::segment(
        $overlapping,
        start: '2026-11-09 11:00:00',
        end: '2026-11-09 13:00:00',
    );
    $first = $overlapping->segments()->where('id', '!=', $second->getKey())->firstOrFail();
    $pairKey = TimesheetOverlapReviewService::pairKey(
        (int) $first->getKey(),
        (int) $second->getKey(),
    );

    $this->actingAs($chefUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $ready])
        ->call('validateTimesheet')
        ->assertForbidden();

    expect($ready->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $overlapping])
        ->set('overlapReason', 'Tentative de décision par un chef d’équipe.')
        ->call('reviewOverlap', $pairKey)
        ->assertForbidden();

    expect($overlapping->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and($overlapping->overlapReviews()->count())->toBe(0);
});

it('applies the same historical perimeter in SQL as the perimeter service', function (): void {
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $chef = TimesheetFixtures::employee($chefUser);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $team = TimesheetFixtures::team();
    TimesheetFixtures::assign($owner, $team, '2026-01-01', '2026-12-31');
    TimesheetFixtures::lead($chef, $team, '2026-01-01', '2026-12-31');

    $inside = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    $outside = TimesheetFixtures::timesheet($owner, week: '2027-01-04');

    $visibleIds = app(TimesheetQuery::class)
        ->visibleTo($chefUser)
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    $expectedIds = Timesheet::query()
        ->where('employee_id', $owner->getKey())
        ->get()
        ->filter(fn (Timesheet $timesheet): bool => app(TimesheetPerimeter::class)
            ->canControl($chefUser, $timesheet))
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($visibleIds)->toBe($expectedIds)
        ->and($visibleIds)->toContain((int) $inside->getKey())
        ->and($visibleIds)->not->toContain((int) $outside->getKey());
});

it('excludes a team member whose assignment does not overlap the leadership within the week', function (): void {
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $chef = TimesheetFixtures::employee($chefUser);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $team = TimesheetFixtures::team();

    TimesheetFixtures::assign($owner, $team, '2026-11-03');
    TimesheetFixtures::lead($chef, $team, '2026-01-01', '2026-11-02');

    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');

    expect(app(TimesheetQuery::class)
        ->visibleTo($chefUser)
        ->whereKey($timesheet->getKey())
        ->exists())->toBeFalse()
        ->and(app(TimesheetPerimeter::class)->canControl($chefUser, $timesheet))->toBeFalse();

    $this->actingAs($chefUser);

    Livewire::test(TimesheetList::class)
        ->assertDontSee($owner->last_name);
});

it('includes a team member when assignment and leadership overlap on an inclusive boundary', function (): void {
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $chef = TimesheetFixtures::employee($chefUser);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $team = TimesheetFixtures::team();

    TimesheetFixtures::assign($owner, $team, '2026-11-02');
    TimesheetFixtures::lead($chef, $team, '2026-01-01', '2026-11-02');

    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');

    expect(app(TimesheetQuery::class)
        ->visibleTo($chefUser)
        ->whereKey($timesheet->getKey())
        ->exists())->toBeTrue()
        ->and(app(TimesheetPerimeter::class)->canControl($chefUser, $timesheet))->toBeTrue();
});
