<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetService;
use App\Services\Timesheets\TimesheetSubmissionService;
use App\Services\Timesheets\TimesheetValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

/**
 * Relevé prêt à valider avec une piste d'audit complète et attribuée.
 */
function pc132CompleteTrail(
    Employee $employee,
    User $owner,
    User $controller,
    string $week = '2026-11-02',
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet($employee, week: $week, status: TimesheetStatus::PretePourValidation);

    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $controller, 'Contrôle satisfaisant');

    return $timesheet;
}

beforeEach(function (): void {
    $this->service = app(TimesheetValidationService::class);
    $this->controls = app(TimesheetControlService::class);
    $this->segments = app(TimesheetSegmentService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chef = TimesheetFixtures::employee($this->chefUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->secondBureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);

    $this->team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chef, $this->team);
});

it('validates a ready timesheet and records the attributed transition', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);

    $result = $this->service->validate($timesheet, $this->bureau);

    expect($result->status)->toBe(TimesheetStatus::Validee);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetValidated->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->bureau->getKey())
        ->and($event->reason)->toBeNull()
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::PretePourValidation->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::Validee->value);
});

it('refuses validation when the audit trail is incomplete', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('refuses validation when the only completeness events predate the latest boundary', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('refuses validation by a role without the validation permission', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('allows Bureau self-validation only with a reason and records a dedicated audit event', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);

    $this->service->validate($timesheet, $this->bureau, 'Auto-validation motivée : contrôle documentaire effectué');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)
            ->where('subject_id', (string) $timesheet->getKey())->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)
            ->where('subject_id', (string) $timesheet->getKey())->firstOrFail()->reason)
        ->toBe('Auto-validation motivée : contrôle documentaire effectué');
});

it('refuses validation by an actor who prepared the timesheet', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->secondBureau);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->secondBureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('refuses validation by an actor who controlled the timesheet', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->secondBureau);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->secondBureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('refuses validation from a status other than ready for validation', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);
});

it('does not create a second validation event on a repeated request', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);

    $this->service->validate($timesheet, $this->bureau);

    expect(fn (): Timesheet => $this->service->validate($timesheet->fresh() ?? $timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(1);
});

it('rolls back the validation when the audit fails', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('reopens a validated timesheet with a normalized reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    $result = $this->service->reopen($timesheet, '  Réouverture demandée  ', $this->bureau);

    expect($result->status)->toBe(TimesheetStatus::Brouillon);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReopened->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->bureau->getKey())
        ->and($event->reason)->toBe('Réouverture demandée')
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::Validee->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::Brouillon->value);
});

it('refuses an invalid reopen reason without changing the status', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    foreach (['', '   ', str_repeat('a', 501)] as $reason) {
        expect(fn (): Timesheet => $this->service->reopen($timesheet, $reason, $this->bureau))
            ->toThrow(ValidationException::class);
    }

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReopened->value)->count())->toBe(0);
});

it('refuses reuse of a timesheet outside the validated state', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->reopen($timesheet, 'Réouverture demandée', $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);
});

it('refuses reopening by a non-authorized actor', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    expect(fn (): Timesheet => $this->service->reopen($timesheet, 'Réouverture demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->reopen($timesheet, 'Réouverture demandée', $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    // L'autorisation prime : un motif invalide ne masque pas le refus d'accès.
    expect(fn (): Timesheet => $this->service->reopen($timesheet, '   ', $this->ownerUser))
        ->toThrow(AuthorizationException::class);
});

it('does not create a second reopen event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    $this->service->reopen($timesheet, 'Réouverture demandée', $this->bureau);

    expect(fn (): Timesheet => $this->service->reopen($timesheet->fresh() ?? $timesheet, 'Réouverture demandée', $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetReopened->value)->count())->toBe(1);
});

it('rolls back the reopening when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->reopen($timesheet, 'Réouverture demandée', $this->bureau))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReopened->value)->count())->toBe(0);
});

it('allows segment edition again after a reopening', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    $this->service->reopen($timesheet, 'Réouverture demandée', $this->bureau);

    $segment = $this->segments->add(
        $timesheet->fresh() ?? $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    expect($segment->exists)->toBeTrue();
});

it('validates a new cycle after a reopening', function (): void {
    $timesheet = app(TimesheetService::class)->openFor($this->owner, '2026-11-02', $this->ownerUser);

    app(TimesheetSegmentService::class)->add(
        $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);
    $this->service->validate($timesheet->fresh() ?? $timesheet, $this->bureau);

    $this->service->reopen($timesheet->fresh() ?? $timesheet, 'Réouverture demandée', $this->secondBureau);

    app(TimesheetSegmentService::class)->add(
        $timesheet->fresh() ?? $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-03 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-03 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);

    $validated = $this->service->validate($timesheet->fresh() ?? $timesheet, $this->bureau);

    expect($validated->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetSubmitted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetReadyForValidation->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetReopened->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(1);

    $events = app(TimesheetHistory::class)->eventsFor($timesheet->fresh() ?? $timesheet);
    $sequences = $events->pluck('sequence')->all();
    $firstValidation = $events->firstWhere('action', AuditAction::TimesheetValidated);
    $reopening = $events->firstWhere('action', AuditAction::TimesheetReopened);

    expect($sequences)->toBe(collect($sequences)->sort()->values()->all())
        ->and($firstValidation?->sequence)->toBeLessThan($reopening?->sequence)
        ->and($events->where('action', AuditAction::TimesheetValidated)->count())->toBe(2)
        ->and($events->where('action', AuditAction::TimesheetSubmitted)->count())->toBe(2)
        ->and($events->where('action', AuditAction::TimesheetCreated)->count())->toBe(1);
});

it('validates after a return to correction and a resume', function (): void {
    $timesheet = app(TimesheetService::class)->openFor($this->owner, '2026-11-02', $this->ownerUser);

    app(TimesheetSegmentService::class)->add(
        $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet, $this->ownerUser);

    $this->controls->requestCorrection($timesheet->fresh() ?? $timesheet, 'Correction demandée', $this->chefUser);
    $this->controls->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->ownerUser);

    app(TimesheetSegmentService::class)->add(
        $timesheet->fresh() ?? $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-03 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-03 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);

    $validated = $this->service->validate($timesheet->fresh() ?? $timesheet, $this->bureau);

    expect($validated->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetReturnedForCorrection->value)
            ->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetCorrectionResumed->value)
            ->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetSubmitted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(1);
});

it('runs the full submission, control and validation flow', function (): void {
    $timesheet = app(TimesheetService::class)->openFor($this->owner, '2026-11-02', $this->ownerUser);

    app(TimesheetSegmentService::class)->add(
        $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet, $this->ownerUser);

    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);

    $validated = $this->service->validate($timesheet->fresh() ?? $timesheet, $this->bureau);

    expect($validated->status)->toBe(TimesheetStatus::Validee);

    foreach ([
        AuditAction::TimesheetCreated,
        AuditAction::TimesheetSubmitted,
        AuditAction::TimesheetReadyForValidation,
        AuditAction::TimesheetValidated,
    ] as $action) {
        expect(AuditEvent::query()
            ->where('action', $action->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->exists())->toBeTrue();
    }

    expect(AuditEvent::query()
        ->where('action', AuditAction::TimesheetValidated->value)
        ->where('actor_id', $this->bureau->getKey())
        ->exists())->toBeTrue();
});

it('refuses validation by a conducteur or the administrator at service level', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);
    $conducteur = TimesheetFixtures::user(Role::Conducteur);
    $admin = TimesheetFixtures::user(Role::Admin);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $conducteur))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $admin))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('refuses validation by a Bureau who returned the timesheet for correction', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);
    TimesheetFixtures::audit(
        $timesheet,
        AuditAction::TimesheetReturnedForCorrection,
        $this->secondBureau,
        'Correction demandée',
    );

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->secondBureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
});

it('refuses a self-validation reason shorter than ten characters', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau, '123456789'))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('accepts a five-hundred-character self-validation reason', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);
    $reason = str_repeat('a', 500);

    $this->service->validate($timesheet, $this->bureau, $reason);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::SelfApprovalRecorded->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->firstOrFail()->reason)->toBe($reason)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->firstOrFail()->reason)->toBe($reason);
});

it('accepts a ten-character self-validation reason and records it once on both events', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    $this->service->validate($timesheet, $this->bureau, '  1234567890  ');

    $selfApproval = AuditEvent::query()
        ->where('action', AuditAction::SelfApprovalRecorded->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->get();

    $validated = AuditEvent::query()
        ->where('action', AuditAction::TimesheetValidated->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->get();

    expect($selfApproval)->toHaveCount(1)
        ->and($selfApproval->first()->reason)->toBe('1234567890')
        ->and($validated)->toHaveCount(1)
        ->and($validated->first()->reason)->toBe('1234567890');
});

it('refuses an oversized self-validation reason', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau, str_repeat('a', 501)))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('refuses validation and reopening by an inactive Bureau account', function (): void {
    $timesheet = pc132CompleteTrail($this->owner, $this->ownerUser, $this->chefUser);
    $this->bureau->forceFill(['is_active' => false])->save();

    expect(fn (): Timesheet => $this->service->validate($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);

    $validated = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09', status: TimesheetStatus::Validee);

    expect(fn (): Timesheet => $this->service->reopen($validated, 'Réouverture demandée', $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect($validated->fresh()?->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReopened->value)->count())->toBe(0);
});

it('allows a Bureau who resumed a correction to validate after a new complete cycle', function (): void {
    $timesheet = app(TimesheetService::class)->openFor($this->owner, '2026-11-02', $this->ownerUser);

    app(TimesheetSegmentService::class)->add(
        $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet, $this->ownerUser);

    $this->controls->requestCorrection($timesheet->fresh() ?? $timesheet, 'Correction demandée', $this->chefUser);
    $this->controls->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->secondBureau);

    app(TimesheetSegmentService::class)->add(
        $timesheet->fresh() ?? $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-03 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-03 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);

    $validated = $this->service->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau);

    expect($validated->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetCorrectionResumed->value)
            ->where('actor_id', $this->secondBureau->getKey())
            ->exists())->toBeTrue();
});

it('lets a Bureau owner complete the workflow and self-validate with a reason', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    TimesheetFixtures::assign($bureauEmployee, $this->team);

    $timesheet = app(TimesheetService::class)->openFor($bureauEmployee, '2026-11-02', $this->bureau);

    app(TimesheetSegmentService::class)->add(
        $timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Paris'),
        ],
        $this->bureau,
    );

    app(TimesheetSubmissionService::class)->submit($timesheet, $this->bureau);
    $this->controls->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser);

    $validated = $this->service->validate(
        $timesheet->fresh() ?? $timesheet,
        $this->bureau,
        'Auto-validation motivée : contrôle documentaire effectué',
    );

    expect($validated->status)->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetCreated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $this->bureau->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetSubmitted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $this->bureau->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('action', AuditAction::SelfApprovalRecorded->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetValidated->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(1);
});

it('never lets a chef, conducteur or administrator self-validate', function (): void {
    foreach ([Role::ChefEquipe, Role::Conducteur, Role::Admin] as $role) {
        $user = TimesheetFixtures::user($role);
        $employee = TimesheetFixtures::employee($user);
        $timesheet = pc132CompleteTrail($employee, $this->ownerUser, $this->chefUser);

        expect(fn (): Timesheet => $this->service->validate($timesheet, $user, 'Auto-validation motivée'))
            ->toThrow(AuthorizationException::class);

        expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation);
    }
});

it('rolls back both self-validation events when the audit fails', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->validate(
        $timesheet,
        $this->bureau,
        'Auto-validation motivée : contrôle documentaire effectué',
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('refuses self-validation by an inactive Bureau owner', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $timesheet = pc132CompleteTrail($bureauEmployee, $this->ownerUser, $this->chefUser);

    $this->bureau->forceFill(['is_active' => false])->save();

    expect(fn (): Timesheet => $this->service->validate(
        $timesheet,
        $this->bureau,
        'Auto-validation motivée : contrôle documentaire effectué',
    ))->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::SelfApprovalRecorded->value)->count())->toBe(0);
});
