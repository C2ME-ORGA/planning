<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
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
