<?php

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\PermissionException;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Timesheets\TimesheetBatchValidationService;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

function pc132BatchReadyTimesheet(
    Employee $employee,
    User $owner,
    User $controller,
    string $week = '2026-11-02',
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet($employee, week: $week, status: TimesheetStatus::PretePourValidation);

    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);
    TimesheetFixtures::audit(
        $timesheet,
        AuditAction::TimesheetReadyForValidation,
        $controller,
        'Contrôle satisfaisant',
    );

    return $timesheet;
}

/**
 * @return array{0: TimesheetSegment, 1: TimesheetSegment}
 */
function pc132BatchAddOverlap(Timesheet $timesheet, string $week): array
{
    $monday = Timesheet::weekFor($week)['start']->toDateString();

    return [
        TimesheetFixtures::segment($timesheet, start: "{$monday} 08:00:00", end: "{$monday} 12:00:00"),
        TimesheetFixtures::segment($timesheet, start: "{$monday} 11:00:00", end: "{$monday} 13:00:00"),
    ];
}

beforeEach(function (): void {
    $this->batch = app(TimesheetBatchValidationService::class);
    $this->reviews = app(TimesheetOverlapReviewService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->secondBureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chef = TimesheetFixtures::employee($this->chefUser);

    $team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $team);
    TimesheetFixtures::lead($this->chef, $team);
});

it('validates every ready timesheet and audits each success individually', function (): void {
    $first = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-02');
    $second = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-09');

    $result = $this->batch->validate($this->bureau, [$first->getKey(), $second->getKey()]);

    expect($result['requested'])->toBe(2)
        ->and($result['succeeded'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($result['partial'])->toBeFalse()
        ->and($result['results'])->toHaveCount(2)
        ->and($first->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($second->fresh()?->status)->toBe(TimesheetStatus::Validee);

    $events = AuditEvent::query()
        ->where('action', AuditAction::TimesheetValidated->value)
        ->whereIn('subject_id', [(string) $first->getKey(), (string) $second->getKey()])
        ->get();

    $bureauId = $this->bureau->getKey();

    expect($events)->toHaveCount(2)
        ->and($events->every(static fn (AuditEvent $event): bool => $event->actor_id === $bureauId))
        ->toBeTrue();
});

it('isolates an invalid timesheet and keeps the other validations successful', function (): void {
    $first = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-02');
    $alreadyValidated = TimesheetFixtures::timesheet(
        $this->owner,
        week: '2026-11-09',
        status: TimesheetStatus::Validee,
    );
    $preparedByActor = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-16');
    TimesheetFixtures::audit($preparedByActor, AuditAction::TimesheetUpdated, $this->bureau);
    $last = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-23');

    $result = $this->batch->validate($this->bureau, [
        $first->getKey(),
        $alreadyValidated->getKey(),
        $preparedByActor->getKey(),
        $last->getKey(),
    ]);

    expect($result['requested'])->toBe(4)
        ->and($result['succeeded'])->toBe(2)
        ->and($result['failed'])->toBe(2)
        ->and($result['partial'])->toBeTrue()
        ->and($first->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($last->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($alreadyValidated->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and($preparedByActor->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);

    $failures = collect($result['results'])->where('result', 'failed')->values();

    expect($failures)->toHaveCount(2)
        ->and($failures[0]['message'])->toContain('pas prêt')
        ->and($failures[1]['message'])->toContain('autoris');

    expect(AuditEvent::query()
        ->where('action', AuditAction::TimesheetValidated->value)
        ->whereIn('subject_id', [(string) $first->getKey(), (string) $last->getKey()])
        ->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $preparedByActor->getKey())
            ->count())->toBe(0);
});

it('applies the overlap review guard per timesheet', function (): void {
    $unreviewed = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-02');
    pc132BatchAddOverlap($unreviewed, '2026-11-02');

    $rejected = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-09');
    [$rejectedFirst, $rejectedSecond] = pc132BatchAddOverlap($rejected, '2026-11-09');
    $this->reviews->review(
        $rejected,
        $rejectedFirst->getKey(),
        $rejectedSecond->getKey(),
        OverlapReviewDecision::Rejected,
        'Horaires incompatibles avec le chantier.',
        $this->bureau,
    );

    $accepted = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-16');
    [$acceptedFirst, $acceptedSecond] = pc132BatchAddOverlap($accepted, '2026-11-16');
    $this->reviews->review(
        $accepted,
        $acceptedFirst->getKey(),
        $acceptedSecond->getKey(),
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    $result = $this->batch->validate($this->bureau, [
        $unreviewed->getKey(),
        $rejected->getKey(),
        $accepted->getKey(),
    ]);

    expect($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(2)
        ->and($unreviewed->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and($rejected->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and($accepted->fresh()?->status)->toBe(TimesheetStatus::Validee);

    $messages = collect($result['results'])->pluck('message')->filter()->implode(' ');

    expect($messages)->toContain('non examiné')
        ->and($messages)->toContain('rejeté');
});

it('keeps the Lot C self-validation rule for a Bureau owner', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $self = pc132BatchReadyTimesheet($bureauEmployee, $this->bureau, $this->chefUser);

    $result = $this->batch->validate($this->bureau, [$self->getKey()]);

    expect($result['succeeded'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($result['results'][0]['message'])->toContain('auto-validation')
        ->and($self->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $self->getKey())
            ->count())->toBe(0);
});

it('deduplicates repeated identifiers before processing', function (): void {
    $first = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-02');
    $second = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-09');

    $result = $this->batch->validate($this->bureau, [
        $first->getKey(),
        (string) $first->getKey(),
        $second->getKey(),
        $first->getKey(),
    ]);

    expect($result['requested'])->toBe(2)
        ->and($result['succeeded'])->toBe(2)
        ->and($result['results'])->toHaveCount(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->count())->toBe(2);
});

it('accepts exactly fifty items and refuses fifty-one before processing', function (): void {
    $fifty = $this->batch->validate($this->bureau, range(1, 50));

    expect($fifty['requested'])->toBe(50)
        ->and($fifty['failed'])->toBe(50)
        ->and($fifty['results'])->toHaveCount(50);

    expect(fn (): array => $this->batch->validate($this->bureau, range(1, 51)))
        ->toThrow(ValidationException::class, '50');

    expect(AuditEvent::query()->count())->toBe(0);
});

it('refuses an empty or malformed selection before processing', function (): void {
    expect(fn (): array => $this->batch->validate($this->bureau, []))
        ->toThrow(ValidationException::class, 'au moins un');

    foreach ([['abc'], [1.5], [[1]], [0], [-1], ['1.5']] as $malformed) {
        expect(fn (): array => $this->batch->validate($this->bureau, $malformed))
            ->toThrow(ValidationException::class, 'invalide');
    }

    expect(AuditEvent::query()->count())->toBe(0);
});

it('requires the Bureau role and the validation permission to launch a batch', function (): void {
    $ready = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    $worker = TimesheetFixtures::user(Role::Ouvrier);
    $chef = TimesheetFixtures::user(Role::ChefEquipe);

    expect(fn (): array => $this->batch->validate($worker, [$ready->getKey()]))
        ->toThrow(AuthorizationException::class);

    PermissionException::query()->create([
        'user_id' => $chef->getKey(),
        'permission' => Permission::TimesheetValidate,
        'reason' => 'Dérogation historique de test.',
        'granted_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
    ]);
    $chef->refresh();

    expect($chef->hasPermission(Permission::TimesheetValidate))->toBeTrue();

    expect(fn (): array => $this->batch->validate($chef, [$ready->getKey()]))
        ->toThrow(AuthorizationException::class);

    $inactive = TimesheetFixtures::user(Role::BureauDirection);
    $inactive->forceFill(['is_active' => false])->save();

    expect(fn (): array => $this->batch->validate($inactive, [$ready->getKey()]))
        ->toThrow(AuthorizationException::class);

    expect($ready->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('isolates an unexpected technical error and continues with the next timesheet', function (): void {
    $first = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-02');
    $second = pc132BatchReadyTimesheet($this->owner, $this->ownerUser, $this->chefUser, '2026-11-09');

    $validation = Mockery::mock(TimesheetValidationService::class);
    $validation->shouldReceive('validate')
        ->once()
        ->andThrow(new RuntimeException('Erreur technique simulée.'));
    $validation->shouldReceive('validate')
        ->once()
        ->andReturnUsing(static fn (Timesheet $timesheet): Timesheet => $timesheet);

    Log::shouldReceive('error')->once();

    $batch = new TimesheetBatchValidationService($validation);
    $result = $batch->validate($this->bureau, [$first->getKey(), $second->getKey()]);

    expect($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($result['results'][0]['message'])->toBe('Une erreur technique a empêché la validation de ce relevé.')
        ->and($result['results'][1]['result'])->toBe('success');
});
