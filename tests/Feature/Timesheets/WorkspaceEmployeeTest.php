<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Livewire\Timesheets\EmployeeDashboard;
use App\Livewire\Timesheets\TimesheetDetail;
use App\Livewire\Timesheets\TimesheetList;
use App\Livewire\Timesheets\WeeklyTimesheet;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Services\Timesheets\TimesheetControlService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\TimesheetFixtures;

function workspaceEmployeeContext(): array
{
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $chef = TimesheetFixtures::employee($chefUser);
    $team = TimesheetFixtures::team();
    TimesheetFixtures::assign($owner, $team);
    TimesheetFixtures::lead($chef, $team);

    return [$ownerUser, $owner, $chefUser];
}

it('redirects a terrain user to the employee workspace and renders the dashboard', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();

    $this->actingAs($ownerUser)
        ->get('/espace')
        ->assertRedirect(route('workspace.employee.dashboard'));

    $this->actingAs($ownerUser)
        ->get('/espace/salarie')
        ->assertOk()
        ->assertSee('Mon espace')
        ->assertSee('Semaine en cours')
        ->assertSee($owner->last_name);
});

it('builds the dashboard counters from SQL aggregations scoped to the linked employee', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $other = TimesheetFixtures::employee($otherUser);

    TimesheetFixtures::timesheet($owner, week: '2026-10-05', status: TimesheetStatus::AControler);
    TimesheetFixtures::timesheet($owner, week: '2026-10-12', status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::timesheet($owner, week: '2026-10-19', status: TimesheetStatus::ACorriger);
    TimesheetFixtures::timesheet($owner, week: '2026-10-26', status: TimesheetStatus::Brouillon);
    TimesheetFixtures::timesheet($owner, week: '2026-11-02', status: TimesheetStatus::Validee);

    TimesheetFixtures::timesheet($other, week: '2026-10-05', status: TimesheetStatus::Validee);
    TimesheetFixtures::timesheet($other, week: '2026-10-12', status: TimesheetStatus::AControler);

    $this->actingAs($ownerUser);

    Livewire::test(EmployeeDashboard::class)
        ->assertViewHas('counts', static fn (array $counts): bool => $counts === [
            'pending' => 2,
            'corrections' => 1,
            'drafts' => 1,
            'validated' => 1,
        ])
        ->assertViewHas('timesheets', static fn ($timesheets): bool => $timesheets->count() === 5);
});

it('renders the weekly planning and opens a draft through the business service', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();

    $this->actingAs($ownerUser)
        ->get('/espace/salarie/semaine/2026-11-02')
        ->assertOk()
        ->assertSee('Ma semaine')
        ->assertSee('Ouvrir ma semaine');

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('openWeek')
        ->assertHasNoErrors()
        ->assertSee('Semaine ouverte');

    $timesheet = Timesheet::query()
        ->where('employee_id', $owner->getKey())
        ->where('period_start', '2026-11-02')
        ->first();

    expect($timesheet)->toBeInstanceOf(Timesheet::class)
        ->and($timesheet?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetCreated->value)
            ->where('subject_id', (string) $timesheet?->getKey())
            ->where('actor_id', $ownerUser->getKey())
            ->exists())->toBeTrue();
});

it('adds, modifies and deletes a segment while signalling overlaps without blocking', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');

    $this->actingAs($ownerUser);

    $component = Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->set('startDate', '2026-11-02')
        ->set('endDate', '2026-11-02')
        ->set('startTime', '08:00')
        ->set('endTime', '12:00')
        ->set('category', 'travail_effectif')
        ->call('saveSegment')
        ->assertHasNoErrors()
        ->assertSee('Segment ajouté');

    $first = $timesheet->segments()->firstOrFail();

    expect($timesheet->segments()->count())->toBe(1)
        ->and($first->start_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 07:00')
        ->and($first->end_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-02 11:00');

    $component
        ->set('startDate', '2026-11-02')
        ->set('endDate', '2026-11-02')
        ->set('startTime', '11:00')
        ->set('endTime', '13:00')
        ->call('saveSegment')
        ->assertHasNoErrors()
        ->assertSee('Chevauchements signalés');

    expect($timesheet->segments()->count())->toBe(2);

    $component
        ->call('editSegment', $first->getKey())
        ->assertSet('editingSegmentId', $first->getKey())
        ->set('endTime', '11:30')
        ->call('saveSegment')
        ->assertHasNoErrors()
        ->assertSee('Segment modifié');

    expect($first->fresh()?->end_at?->utc()->format('H:i'))->toBe('10:30')
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetUpdated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(3);

    $component
        ->call('deleteSegment', $first->getKey())
        ->assertHasNoErrors()
        ->assertSee('Segment supprimé');

    expect($timesheet->segments()->count())->toBe(1);
});

it('signals a segment longer than twelve hours without blocking the entry', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    TimesheetFixtures::segment(
        $timesheet,
        start: '2026-11-02 06:00:00',
        end: '2026-11-02 19:00:00',
    );

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->assertOk()
        ->assertSee('Avertissement de durée')
        ->assertSee('plus de 12 heures')
        ->assertSee('13 h');

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->assertOk()
        ->assertSee('Avertissement de durée')
        ->assertSee('plus de 12 heures');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and($timesheet->segments()->count())->toBe(1);
});

it('refuses to edit a locked timesheet and keeps the stored segments intact', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet(
        $owner,
        week: '2026-11-02',
        status: TimesheetStatus::AControler,
    );
    $segment = TimesheetFixtures::segment($timesheet);

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->set('startDate', '2026-11-02')
        ->set('endDate', '2026-11-02')
        ->set('startTime', '14:00')
        ->set('endTime', '16:00')
        ->call('saveSegment')
        ->assertForbidden();

    expect($timesheet->segments()->count())->toBe(1)
        ->and($segment->fresh())->not->toBeNull();
});

it('submits, displays a correction and resumes the cycle before resubmitting', function (): void {
    [$ownerUser, $owner, $chefUser] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    TimesheetFixtures::segment($timesheet);

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Relevé transmis');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);

    app(TimesheetControlService::class)->requestCorrection(
        $timesheet->fresh() ?? $timesheet,
        'Horaires à préciser sur le chantier.',
        $chefUser,
    );

    $this->actingAs($ownerUser)
        ->get('/espace/salarie/semaine/2026-11-02')
        ->assertOk()
        ->assertSee('Correction demandée')
        ->assertSee('Horaires à préciser sur le chantier.');

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('resumeCorrection')
        ->assertHasNoErrors()
        ->assertSee('Correction reprise');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('submit')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetCorrectionResumed->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $ownerUser->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetSubmitted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2);
});

it('does not allow an employee to send a draft directly to validation', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    TimesheetFixtures::segment($timesheet);

    $this->actingAs($ownerUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->assertDontSee('Transmettre directement à la validation')
        ->set('directReason', 'Tentative de transmission directe.')
        ->call('sendToValidation')
        ->assertForbidden();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('does not expose the Bureau correction interface to an employee', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    TimesheetFixtures::segment($timesheet);

    $this->actingAs($ownerUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->assertDontSee('Ajouter un segment')
        ->call('startAddingSegment')
        ->assertForbidden();

    expect($timesheet->segments()->count())->toBe(1);
});

it('navigates between weeks through full-page Livewire redirects', function (): void {
    [$ownerUser] = workspaceEmployeeContext();

    $this->actingAs($ownerUser);

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('previousWeek')
        ->assertRedirect(route('workspace.employee.week', ['week' => '2026-10-26']));

    Livewire::test(WeeklyTimesheet::class, ['week' => '2026-11-02'])
        ->call('nextWeek')
        ->assertRedirect(route('workspace.employee.week', ['week' => '2026-11-09']));
});

it('never exposes the timesheet of another employee', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $other = TimesheetFixtures::employee($otherUser);
    $otherTimesheet = TimesheetFixtures::timesheet($other, week: '2026-11-09');

    $this->actingAs($ownerUser)
        ->get(route('workspace.timesheets.show', $otherTimesheet))
        ->assertForbidden();

    $this->actingAs($ownerUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $otherTimesheet])
        ->assertForbidden();

    $ownTimesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-09');

    Livewire::test(TimesheetDetail::class, ['timesheet' => $ownTimesheet])
        ->assertOk();

    Livewire::test(TimesheetList::class)
        ->assertOk()
        ->assertDontSee($other->last_name);
});

it('blocks an employee from the privileged queues even through a Livewire property', function (): void {
    [$ownerUser] = workspaceEmployeeContext();
    $this->actingAs($ownerUser);

    Livewire::test(TimesheetList::class)
        ->set('queue', 'chevauchements')
        ->call('$refresh')
        ->assertForbidden();
});

it('locks the timesheet identifier exposed by the detail component', function (): void {
    [$ownerUser, $owner] = workspaceEmployeeContext();
    $other = TimesheetFixtures::employee(TimesheetFixtures::user(Role::Ouvrier));
    $ownTimesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');
    $otherTimesheet = TimesheetFixtures::timesheet($other, week: '2026-11-02');

    $this->actingAs($ownerUser);

    expect(fn () => Livewire::test(TimesheetDetail::class, ['timesheet' => $ownTimesheet])
        ->set('timesheetId', $otherTimesheet->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
