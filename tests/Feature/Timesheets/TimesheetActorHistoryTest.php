<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Services\Audit\AuditLogger;
use App\Services\Timesheets\TimesheetActorHistory;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->history = app(TimesheetActorHistory::class);
    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->chef = TimesheetFixtures::user(Role::ChefEquipe);
    $this->timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);
});

it('identifies the creator and an editor as preparers', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetUpdated, $this->bureau);

    expect($this->history->isPreparedBy($this->ownerUser, $this->timesheet))->toBeTrue()
        ->and($this->history->isPreparedBy($this->bureau, $this->timesheet))->toBeTrue()
        ->and($this->history->isPreparedBy($this->chef, $this->timesheet))->toBeFalse();
});

it('identifies a user who returned or prepared the timesheet as a controller', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReturnedForCorrection, $this->chef, 'Correction demandée');

    expect($this->history->hasControlled($this->chef, $this->timesheet))->toBeTrue()
        ->and($this->history->hasControlled($this->bureau, $this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->bureau, 'Contrôle satisfaisant');

    expect($this->history->hasControlled($this->bureau, $this->timesheet))->toBeTrue();
});

it('treats a missing audit trail as incomplete instead of as proof of absence', function (): void {
    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('requires the ready-for-validation event for a timesheet in that status', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('ignores audit events attached to another timesheet', function (): void {
    $other = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09');
    TimesheetFixtures::audit($other, AuditAction::TimesheetCreated, $this->bureau);

    expect($this->history->isPreparedBy($this->bureau, $this->timesheet))->toBeFalse();
});

it('does not treat an unattributed event as a complete trail', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::auditWithoutActor($this->timesheet, AuditAction::TimesheetSubmitted);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('requires the completeness events after the latest reopen boundary', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('treats a correction resume as a cycle boundary', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCorrectionResumed, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('refuses a cycle boundary without an actor', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    TimesheetFixtures::auditWithoutActor($this->timesheet, AuditAction::TimesheetReopened, 'Réouverture demandée');

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('refuses a cycle whose control precedes the latest submission', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('requires a new attributed submission and control for each cycle', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCorrectionResumed, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    // Un contrôle seul ne suffit pas : il doit suivre une nouvelle transmission.
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('refuses validation when any earlier cycle boundary has no actor', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    TimesheetFixtures::auditWithoutActor($this->timesheet, AuditAction::TimesheetReopened, 'Réouverture demandée');
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('uses the latest submission of the cycle to pair the control', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();

    // Une transmission plus récente sans nouveau contrôle invalide le cycle.
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    // Un contrôle postérieur à cette dernière transmission rétablit la piste.
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('accepts several control events after the retained submission', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReadyForValidation, $this->chef, 'Contrôle satisfaisant');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('does not exclude an actor only because they validated or reopened', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetValidated, $this->bureau);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect($this->history->isPreparedBy($this->bureau, $this->timesheet))->toBeFalse()
        ->and($this->history->hasControlled($this->bureau, $this->timesheet))->toBeFalse();
});

it('does not treat a correction resume as a preparation action', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCorrectionResumed, $this->bureau);

    expect($this->history->isPreparedBy($this->bureau, $this->timesheet))->toBeFalse();
});

it('treats a direct Bureau transmission as a control action', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSentToValidation, $this->bureau, 'Correction effectuée par le Bureau.');

    expect($this->history->isPreparedBy($this->bureau, $this->timesheet))->toBeFalse()
        ->and($this->history->hasControlled($this->bureau, $this->timesheet))->toBeTrue();
});

it('accepts a direct Bureau transmission as a complete trail', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSentToValidation, $this->bureau, 'Correction effectuée par le Bureau.');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('requires the direct transmission after the latest cycle boundary', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSentToValidation, $this->bureau, 'Correction effectuée par le Bureau.');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetSentToValidation, $this->bureau, 'Nouvelle correction après réouverture.');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeTrue();
});

it('does not accept an unattributed direct transmission', function (): void {
    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::auditWithoutActor($this->timesheet, AuditAction::TimesheetSentToValidation, 'Correction effectuée par le Bureau.');

    expect($this->history->hasCompleteTrail($this->timesheet))->toBeFalse();
});

it('recognizes an effective segment update by the actor in the current cycle', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
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

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeTrue();
});

it('recognizes segment addition and deletion as corrections', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_added',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeTrue();

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_deleted',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeTrue();
});

it('does not treat a no-op segment update as a correction', function (): void {
    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_updated',
            'segment_id' => 1,
            'changed_fields' => [],
            'before' => [],
            'after' => [],
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeFalse();

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_updated',
            'segment_id' => 1,
            'changed_fields' => ['end_at'],
            'before' => ['end_at' => '2026-11-02T08:00:00Z'],
            'after' => ['end_at' => '2026-11-02T08:00:00Z'],
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeFalse();
});

it('does not accept a correction made by another actor or on another timesheet', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_added',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->chef,
    );

    $other = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09');

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $other,
        [
            'operation' => 'segment_added',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeFalse();
});

it('requires the correction after the latest cycle boundary', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_added',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeTrue();

    TimesheetFixtures::audit($this->timesheet, AuditAction::TimesheetReopened, $this->bureau, 'Réouverture demandée');

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeFalse();

    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        [
            'operation' => 'segment_added',
            'segment_id' => (int) $segment->getKey(),
        ],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeTrue();
});

it('ignores timesheet updates that are not segment corrections', function (): void {
    app(AuditLogger::class)->record(
        AuditAction::TimesheetUpdated,
        $this->timesheet,
        ['operation' => 'status_updated'],
        null,
        $this->bureau,
    );

    expect($this->history->hasCorrectedInCurrentCycle($this->bureau, $this->timesheet))->toBeFalse();
});
