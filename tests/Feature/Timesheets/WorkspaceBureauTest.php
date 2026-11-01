<?php

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Livewire\Timesheets\Bureau\BatchValidation;
use App\Livewire\Timesheets\Bureau\Journal;
use App\Livewire\Timesheets\TimesheetDetail;
use App\Livewire\Timesheets\TimesheetList;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetActorHistory;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetSubmissionService;
use Livewire\Livewire;
use Tests\Support\TimesheetFixtures;

/**
 * @return array{0: User, 1: User, 2: User, 3: User, 4: Employee, 5: Employee}
 */
function workspaceBureauContext(): array
{
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $controllerBureau = TimesheetFixtures::user(Role::BureauDirection);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $controller = TimesheetFixtures::user(Role::ChefEquipe);
    $bureauEmployee = TimesheetFixtures::employee($bureau);

    return [$bureau, $controllerBureau, $ownerUser, $controller, $bureauEmployee, $owner];
}

function workspaceBureauReady(
    Employee $employee,
    User $owner,
    User $controller,
    string $week = '2026-11-02',
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet(
        $employee,
        week: $week,
        status: TimesheetStatus::PretePourValidation,
    );
    TimesheetFixtures::segment($timesheet, start: "{$week} 08:00:00", end: "{$week} 12:00:00");
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);
    TimesheetFixtures::audit(
        $timesheet,
        AuditAction::TimesheetReadyForValidation,
        $controller,
        'Contrôle du relevé satisfaisant.',
    );

    return $timesheet;
}

function workspaceBureauUnderControl(
    Employee $employee,
    User $owner,
    string $week,
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet(
        $employee,
        week: $week,
        status: TimesheetStatus::AControler,
    );
    TimesheetFixtures::segment($timesheet, start: "{$week} 08:00:00", end: "{$week} 12:00:00");
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);

    return $timesheet;
}

/**
 * @return array{0: Timesheet, 1: string}
 */
function workspaceBureauOverlap(Timesheet $timesheet, string $week): array
{
    $second = TimesheetFixtures::segment($timesheet, start: "{$week} 11:00:00", end: "{$week} 13:00:00");
    $first = $timesheet->segments()->where('id', '!=', $second->getKey())->firstOrFail();

    return [
        $timesheet,
        TimesheetOverlapReviewService::pairKey((int) $first->getKey(), (int) $second->getKey()),
    ];
}

it('opens every Bureau processing queue and dashboard', function (): void {
    [$bureau] = workspaceBureauContext();
    $this->actingAs($bureau);

    $this->get('/espace')->assertRedirect(route('workspace.bureau.dashboard'));

    foreach ([
        '/espace/bureau',
        '/espace/bureau/validation-en-masse',
        '/espace/bureau/journal',
        '/espace/releves',
    ] as $url) {
        $this->get($url)->assertOk();
    }

    foreach (['a-controler', 'pretes', 'a-corriger', 'validees', 'chevauchements'] as $queue) {
        $this->get(route('workspace.timesheets.queue', ['queue' => $queue]))->assertOk();
    }
});

it('accepts an overlap with a mandatory reason and then validates the timesheet', function (): void {
    [$bureau, , $ownerUser, $controller, , $owner] = workspaceBureauContext();
    [$timesheet, $pairKey] = workspaceBureauOverlap(
        workspaceBureauReady($owner, $ownerUser, $controller),
        '2026-11-02',
    );

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('overlapReason', 'Chevauchement justifié par la continuité du chantier.')
        ->set('overlapDecision', OverlapReviewDecision::Accepted->value)
        ->call('reviewOverlap', $pairKey)
        ->assertHasNoErrors();

    expect($timesheet->overlapReviews()->count())->toBe(1)
        ->and($timesheet->overlapReviews()->first()?->decision)
        ->toBe(OverlapReviewDecision::Accepted)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetOverlapReviewAccepted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $bureau->getKey())
            ->exists())->toBeTrue();

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('validateTimesheet')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee);
});

it('rejects an overlap with a mandatory reason and blocks final validation', function (): void {
    [$bureau, , $ownerUser, $controller, , $owner] = workspaceBureauContext();
    [$timesheet, $pairKey] = workspaceBureauOverlap(
        workspaceBureauReady($owner, $ownerUser, $controller),
        '2026-11-02',
    );

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('reviewOverlap', $pairKey)
        ->assertHasErrors('overlapReason');

    expect($timesheet->overlapReviews()->count())->toBe(0);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('overlapReason', 'Horaires incompatibles avec les règles du chantier.')
        ->set('overlapDecision', OverlapReviewDecision::Rejected->value)
        ->call('reviewOverlap', $pairKey)
        ->assertHasNoErrors();

    expect($timesheet->overlapReviews()->first()?->decision)->toBe(OverlapReviewDecision::Rejected);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('validateTimesheet')
        ->assertHasErrors('timesheet');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('identifies a review made obsolete by a new correction cycle', function (): void {
    [$bureau, $controllerBureau, $ownerUser, , , $owner] = workspaceBureauContext();
    [$timesheet, $pairKey] = workspaceBureauOverlap(
        workspaceBureauUnderControl($owner, $ownerUser, '2026-11-02'),
        '2026-11-02',
    );

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('overlapReason', 'Chevauchement accepté avant le retour en correction.')
        ->set('overlapDecision', OverlapReviewDecision::Accepted->value)
        ->call('reviewOverlap', $pairKey)
        ->assertHasNoErrors();

    $control = app(TimesheetControlService::class);
    $control->requestCorrection($timesheet->fresh() ?? $timesheet, 'Correction demandée par le Bureau.', $controllerBureau);
    $control->resumeCorrection($timesheet->fresh() ?? $timesheet, $ownerUser);
    app(TimesheetSubmissionService::class)->submit($timesheet->fresh() ?? $timesheet, $ownerUser);
    $control->markReadyForValidation(
        $timesheet->fresh() ?? $timesheet,
        'Nouveau contrôle après reprise de correction.',
        $controllerBureau,
    );

    $timesheet->refresh();

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->assertSee('Revue obsolète')
        ->assertSee('nouveau cycle de correction');

    expect($timesheet->overlapReviews()->first()?->cycle_sequence)
        ->toBeLessThan(app(TimesheetActorHistory::class)->currentCycleSequence($timesheet));

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('overlapReason', 'Nouvelle revue du chevauchement après correction.')
        ->set('overlapDecision', OverlapReviewDecision::Accepted->value)
        ->call('reviewOverlap', $pairKey)
        ->assertHasNoErrors();

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('validateTimesheet')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($timesheet->overlapReviews()->first()?->decision)->toBe(OverlapReviewDecision::Accepted);
});

it('requires a reason for a Bureau self-validation and records both audit events', function (): void {
    [$bureau, , , $controller, $bureauEmployee] = workspaceBureauContext();
    $timesheet = workspaceBureauReady($bureauEmployee, $bureau, $controller);

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('validateTimesheet')
        ->assertHasErrors('validationReason');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('validationReason', 'Auto-validation motivée du relevé Bureau.')
        ->call('validateTimesheet')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::SelfApprovalRecorded->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $bureau->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $bureau->getKey())
            ->exists())->toBeTrue();
});

it('runs a batch through the existing service and displays a partial outcome', function (): void {
    [$bureau, , $ownerUser, $controller, , $owner] = workspaceBureauContext();
    $first = workspaceBureauReady($owner, $ownerUser, $controller, '2026-11-02');
    $second = workspaceBureauReady($owner, $ownerUser, $controller, '2026-11-09');
    $invalid = workspaceBureauUnderControl($owner, $ownerUser, '2026-11-16');

    $this->actingAs($bureau);

    Livewire::test(BatchValidation::class)
        ->call('validateSelection')
        ->assertHasErrors('selected');

    Livewire::test(BatchValidation::class)
        ->set('selected', range(1, 51))
        ->call('validateSelection')
        ->assertHasErrors('selected');

    $component = Livewire::test(BatchValidation::class)
        ->set('selected', [$first->getKey(), $second->getKey(), $invalid->getKey()])
        ->call('validateSelection')
        ->assertHasNoErrors()
        ->assertSee('Bilan du traitement')
        ->assertSee('Traitement partiel');

    expect($first->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($second->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($invalid->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and($component->get('results')['requested'])->toBe(3)
        ->and($component->get('results')['succeeded'])->toBe(2)
        ->and($component->get('results')['failed'])->toBe(1)
        ->and($component->get('results')['partial'])->toBeTrue();
});

it('reopens a validated timesheet with a mandatory reason', function (): void {
    [$bureau, , , , , $owner] = workspaceBureauContext();
    $timesheet = TimesheetFixtures::timesheet(
        $owner,
        week: '2026-11-02',
        status: TimesheetStatus::Validee,
    );

    $this->actingAs($bureau);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->call('reopen')
        ->assertHasErrors('reopenReason');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->set('reopenReason', 'Correction demandée après validation.')
        ->call('reopen')
        ->assertHasNoErrors();

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetReopened->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $bureau->getKey())
            ->exists())->toBeTrue();
});

it('shows the global read-only journal with server-side filters', function (): void {
    [$bureau, , $ownerUser, $controller, , $owner] = workspaceBureauContext();
    $timesheet = workspaceBureauReady($owner, $ownerUser, $controller);

    $this->actingAs($bureau)
        ->get('/espace/bureau/journal')
        ->assertOk()
        ->assertSee('Journal des relevés')
        ->assertSee($owner->last_name)
        ->assertSee('Relevé transmis')
        ->assertSee('Relevé prêt pour validation');

    $this->actingAs($bureau);

    Livewire::test(Journal::class)
        ->set('action', AuditAction::TimesheetSubmitted->value)
        ->assertViewHas('entries', function (array $entries): bool {
            return $entries !== []
                && collect($entries)->every(
                    static fn (array $entry): bool => $entry['action'] === AuditAction::TimesheetSubmitted->value,
                );
        });

    Livewire::test(Journal::class)
        ->set('employeeId', (string) ($timesheet->employee_id + 999))
        ->assertViewHas('entries', static fn (array $entries): bool => $entries === []);
});

it('displays the actor role recorded at the time of the event after a role change', function (): void {
    $chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $owner = TimesheetFixtures::employee($ownerUser);
    $timesheet = TimesheetFixtures::timesheet($owner, week: '2026-11-02');

    TimesheetFixtures::audit(
        $timesheet,
        AuditAction::TimesheetReadyForValidation,
        $chefUser,
        'Contrôle du relevé satisfaisant.',
    );

    $chefUser->forceFill(['role' => Role::BureauDirection->value])->save();

    $events = app(TimesheetHistory::class)->eventsFor($timesheet);
    $event = collect(app(TimesheetHistory::class)->presentMany($events))
        ->firstWhere('action', AuditAction::TimesheetReadyForValidation->value);

    expect($event['actor_role'])->toBe(Role::ChefEquipe->label());

    $this->actingAs($chefUser);

    Livewire::test(TimesheetDetail::class, ['timesheet' => $timesheet])
        ->assertSee(Role::ChefEquipe->label());
});

it('keeps the historical role when the actor account is no longer linked', function (): void {
    $event = new AuditEvent([
        'sequence' => 1,
        'occurred_at' => now(),
        'actor_id' => null,
        'actor_role' => Role::ChefEquipe->value,
        'action' => AuditAction::TimesheetReadyForValidation->value,
        'subject_type' => Timesheet::class,
        'subject_id' => '1',
        'hash' => str_repeat('a', 64),
    ]);

    $presented = app(TimesheetHistory::class)->present($event);

    expect($presented['actor'])->toBeNull()
        ->and($presented['actor_role'])->toBe(Role::ChefEquipe->label());
});

it('applies the status filter inside the overlap queue', function (): void {
    [$bureau, , $ownerUser, $controller, , $owner] = workspaceBureauContext();
    $otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $other = TimesheetFixtures::employee($otherUser);

    [$underControl] = workspaceBureauOverlap(
        workspaceBureauUnderControl($owner, $ownerUser, '2026-11-02'),
        '2026-11-02',
    );
    [$ready] = workspaceBureauOverlap(
        workspaceBureauReady($other, $otherUser, $controller, '2026-11-02'),
        '2026-11-02',
    );

    $this->actingAs($bureau);

    Livewire::test(TimesheetList::class, ['queue' => 'chevauchements'])
        ->set('status', TimesheetStatus::AControler->value)
        ->assertViewHas('timesheets', function ($paginator) use ($underControl, $ready): bool {
            $ids = $paginator->getCollection()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            return $ids === [(int) $underControl->getKey()]
                && ! in_array((int) $ready->getKey(), $ids, true);
        });
});

it('keeps the Bureau perimeter bypass impossible for every queue through Livewire state', function (string $queue): void {
    [$bureau] = workspaceBureauContext();
    $this->actingAs($bureau);

    Livewire::test(TimesheetList::class)
        ->set('queue', $queue)
        ->call('$refresh')
        ->assertOk();
})->with(['a-controler', 'pretes', 'a-corriger', 'validees', 'chevauchements']);
