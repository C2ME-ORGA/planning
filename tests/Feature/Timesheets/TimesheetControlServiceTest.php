<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetService;
use App\Services\Timesheets\TimesheetValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

function pc133CorrectSegmentAsBureau(
    Timesheet $timesheet,
    User $bureau,
    string $start = '2026-11-02 09:00',
    string $end = '2026-11-02 13:00',
): TimesheetSegment {
    $segment = $timesheet->segments()->first();

    if (! $segment instanceof TimesheetSegment) {
        $segment = TimesheetFixtures::segment(
            $timesheet,
            start: '2026-11-02 08:00:00',
            end: '2026-11-02 12:00:00',
        );
    }

    return app(TimesheetSegmentService::class)->update(
        $segment,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse($start, 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse($end, 'Europe/Paris'),
        ],
        $bureau,
    );
}

beforeEach(function (): void {
    $this->service = app(TimesheetControlService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chef = TimesheetFixtures::employee($this->chefUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->outOfPerimeterChef = TimesheetFixtures::user(Role::ChefEquipe);

    $this->team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chef, $this->team);
});

it('returns a submitted timesheet for correction with a normalized reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $result = $this->service->requestCorrection($timesheet, '  Correction demandée  ', $this->chefUser);

    expect($result->status)->toBe(TimesheetStatus::ACorriger);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReturnedForCorrection->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->chefUser->getKey())
        ->and($event->reason)->toBe('Correction demandée')
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::AControler->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::ACorriger->value);
});

it('lets the Bureau return a timesheet for correction', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect($this->service->requestCorrection($timesheet, 'Correction demandée', $this->bureau)->status)
        ->toBe(TimesheetStatus::ACorriger);
});

it('refuses correction by the owner or a controller outside the perimeter', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    // L'autorisation prime sur la validation du motif : un acteur non habilité
    // reçoit un refus d'autorisation même avec un motif invalide.
    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, '   ', $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->outOfPerimeterChef))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('refuses a missing, blank or oversized correction reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    foreach (['', '   ', str_repeat('a', 501)] as $reason) {
        expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, $reason, $this->chefUser))
            ->toThrow(ValidationException::class);
    }

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('refuses correction from an incompatible status', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Brouillon);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('does not create a second correction event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet->fresh() ?? $timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(1);
});

it('rolls back the correction when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('lets the owner resume a correction and records the transition', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    $result = $this->service->resumeCorrection($timesheet, $this->ownerUser);

    expect($result->status)->toBe(TimesheetStatus::Brouillon);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetCorrectionResumed->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->ownerUser->getKey())
        ->and($event->reason)->toBeNull()
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::ACorriger->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::Brouillon->value);
});

it('lets the Bureau resume a correction and refuses other actors', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->otherUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($this->service->resumeCorrection($timesheet, $this->bureau)->status)
        ->toBe(TimesheetStatus::Brouillon);
});

it('does not create a second resume event when the timesheet is already a draft', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    $this->service->resumeCorrection($timesheet, $this->ownerUser);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetCorrectionResumed->value)->count())->toBe(1);
});

it('rolls back the resume when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->ownerUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::ACorriger)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetCorrectionResumed->value)->count())->toBe(0);
});

it('marks a controlled timesheet ready for validation with a normalized reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $result = $this->service->markReadyForValidation($timesheet, '  Contrôle satisfaisant  ', $this->chefUser);

    expect($result->status)->toBe(TimesheetStatus::PretePourValidation);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReadyForValidation->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->chefUser->getKey())
        ->and($event->reason)->toBe('Contrôle satisfaisant')
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::PretePourValidation->value);
});

it('refuses a ready-for-validation request without a valid reason or permission', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, '', $this->chefUser))
        ->toThrow(ValidationException::class);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->outOfPerimeterChef))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});

it('does not create a second ready-for-validation event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->chefUser);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(1);
});

it('refuses to mark a draft ready for validation outside the control step', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);

    expect(fn (): Timesheet => $this->service->markReadyForValidation(
        $timesheet,
        'Contrôle satisfaisant',
        $this->chefUser,
    ))->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->markReadyForValidation(
        $timesheet,
        'Contrôle satisfaisant',
        $this->bureau,
    ))->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});

it('rolls back the ready-for-validation transition when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->chefUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});

it('requires at least ten characters for control reasons', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, '123456789', $this->chefUser))
        ->toThrow(ValidationException::class);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, '123456789', $this->chefUser))
        ->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});

it('refuses every control action by an inactive account', function (): void {
    $toControl = TimesheetFixtures::timesheet($this->owner, week: '2026-11-02', status: TimesheetStatus::AControler);
    $toResume = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09', status: TimesheetStatus::ACorriger);

    $this->chefUser->forceFill(['is_active' => false])->save();
    $this->ownerUser->forceFill(['is_active' => false])->save();

    expect(fn (): Timesheet => $this->service->requestCorrection($toControl, 'Correction demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($toControl, 'Contrôle satisfaisant', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->resumeCorrection($toResume, $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect($toControl->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and($toResume->fresh()?->status)->toBe(TimesheetStatus::ACorriger)
        ->and(AuditEvent::query()->whereIn('action', [
            AuditAction::TimesheetReturnedForCorrection->value,
            AuditAction::TimesheetReadyForValidation->value,
            AuditAction::TimesheetCorrectionResumed->value,
        ])->count())->toBe(0);
});

it('lets the Bureau send a corrected draft directly to final validation with a reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->bureau);

    $result = $this->service->sendToValidation(
        $timesheet,
        '  Correction effectuée par le Bureau.  ',
        $this->bureau,
    );

    expect($result->status)->toBe(TimesheetStatus::PretePourValidation);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetSentToValidation->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->bureau->getKey())
        ->and($event->reason)->toBe('Correction effectuée par le Bureau.')
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::Brouillon->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::PretePourValidation->value)
        ->and($event->changes['path'] ?? null)->toBe('bureau_correction');
});

it('refuses direct transmission by the owner, a chef or a conducteur', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    $conducteur = TimesheetFixtures::user(Role::Conducteur);

    foreach ([$this->ownerUser, $this->chefUser, $this->outOfPerimeterChef, $conducteur] as $actor) {
        expect(fn (): Timesheet => $this->service->sendToValidation(
            $timesheet,
            'Correction effectuée par le Bureau.',
            $actor,
        ))->toThrow(AuthorizationException::class);
    }

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses an invalid direct transmission reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->bureau);

    foreach (['', '   ', '123456789', str_repeat('a', 501)] as $reason) {
        expect(fn (): Timesheet => $this->service->sendToValidation($timesheet, $reason, $this->bureau))
            ->toThrow(ValidationException::class);
    }

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses an empty draft or an out-of-week segment for direct transmission', function (): void {
    $empty = TimesheetFixtures::timesheet($this->owner);
    $emptySegment = TimesheetFixtures::segment($empty);
    pc133CorrectSegmentAsBureau($empty, $this->bureau);
    app(TimesheetSegmentService::class)->delete($emptySegment, $this->bureau);

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $empty,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    $invalid = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09');
    TimesheetFixtures::segment(
        $invalid,
        start: '2026-11-09 08:00:00',
        end: '2026-11-09 12:00:00',
    );
    pc133CorrectSegmentAsBureau(
        $invalid,
        $this->bureau,
        start: '2026-11-09 09:00',
        end: '2026-11-09 13:00',
    );
    TimesheetFixtures::segment(
        $invalid,
        start: '2026-11-08 22:00:00',
        end: '2026-11-09 02:00:00',
    );

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $invalid,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($empty->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and($invalid->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses direct transmission without an effective Bureau correction', function (): void {
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

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses direct transmission when another user made the correction', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->ownerUser);

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses a no-op Bureau correction as proof for direct transmission', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment(
        $timesheet,
        start: '2026-11-02 08:00:00',
        end: '2026-11-02 12:00:00',
    );
    pc133CorrectSegmentAsBureau(
        $timesheet,
        $this->bureau,
        start: '2026-11-02 08:00',
        end: '2026-11-02 12:00',
    );

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses a Bureau correction made before the latest cycle boundary', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->bureau);

    TimesheetFixtures::audit(
        $timesheet,
        AuditAction::TimesheetCorrectionResumed,
        $this->ownerUser,
    );

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('refuses to reuse a Bureau correction from before a reopening', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);
    $segment = TimesheetFixtures::segment($timesheet);

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $timesheet,
        [
            'operation' => 'segment_updated',
            'segment_id' => (int) $segment->getKey(),
            'changed_fields' => ['end_at'],
            'before' => ['end_at' => '2026-11-02T07:00:00Z'],
            'after' => ['end_at' => '2026-11-02T08:00:00Z'],
        ],
        null,
        $this->bureau,
    );

    app(TimesheetValidationService::class)->reopen(
        $timesheet->fresh() ?? $timesheet,
        'Réouverture demandée.',
        $this->bureau,
    );

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(ValidationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('does not create a second direct transmission event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->bureau);

    $this->service->sendToValidation($timesheet, 'Correction effectuée par le Bureau.', $this->bureau);

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet->fresh() ?? $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(1);
});

it('rolls back the direct transmission when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);
    pc133CorrectSegmentAsBureau($timesheet, $this->bureau);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});

it('refuses direct transmission by an inactive Bureau account', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::segment($timesheet);

    $inactiveBureau = TimesheetFixtures::user(Role::BureauDirection);
    $inactiveBureau->forceFill(['is_active' => false])->save();

    expect(fn (): Timesheet => $this->service->sendToValidation(
        $timesheet,
        'Correction effectuée par le Bureau.',
        $inactiveBureau,
    ))->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSentToValidation->value)->count())->toBe(0);
});
