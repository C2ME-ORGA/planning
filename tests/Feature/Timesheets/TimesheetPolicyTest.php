<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Policies\TimesheetPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->policy = app(TimesheetPolicy::class);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->otherEmployee = TimesheetFixtures::employee($this->otherUser);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chefEmployee = TimesheetFixtures::employee($this->chefUser);
    $this->conducteurUser = TimesheetFixtures::user(Role::Conducteur);
    $this->conducteurEmployee = TimesheetFixtures::employee($this->conducteurUser);
    $this->team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chefEmployee, $this->team);
});

it('distinguishes the owner, the perimeter controller, the Bureau and the administrator', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->policy->view($this->ownerUser, $timesheet))->toBeTrue()
        ->and($this->policy->view($this->otherUser, $timesheet))->toBeFalse()
        ->and($this->policy->view($this->chefUser, $timesheet))->toBeTrue()
        ->and($this->policy->view($this->bureau, $timesheet))->toBeTrue()
        ->and($this->policy->view(TimesheetFixtures::user(Role::Admin), $timesheet))->toBeFalse();
});

it('allows viewAny only to timesheet roles', function (): void {
    expect($this->policy->viewAny($this->ownerUser))->toBeTrue()
        ->and($this->policy->viewAny($this->chefUser))->toBeTrue()
        ->and($this->policy->viewAny($this->conducteurUser))->toBeTrue()
        ->and($this->policy->viewAny($this->bureau))->toBeTrue()
        ->and($this->policy->viewAny(TimesheetFixtures::user(Role::Admin)))->toBeFalse();
});

it('exposes a Gate-compatible global create ability reserved to the Bureau', function (): void {
    $admin = TimesheetFixtures::user(Role::Admin);

    expect(Gate::forUser($this->bureau)->allows('create', Timesheet::class))->toBeTrue()
        ->and(Gate::forUser($this->ownerUser)->allows('create', Timesheet::class))->toBeFalse()
        ->and(Gate::forUser($this->chefUser)->allows('create', Timesheet::class))->toBeFalse()
        ->and(Gate::forUser($this->conducteurUser)->allows('create', Timesheet::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('create', Timesheet::class))->toBeFalse();
});

it('authorizes draft creation for a target employee through createFor', function (): void {
    $draft = new Timesheet(['employee_id' => $this->owner->getKey()]);
    $chefDraft = new Timesheet(['employee_id' => $this->chefEmployee->getKey()]);
    $admin = TimesheetFixtures::user(Role::Admin);

    expect(Gate::forUser($this->ownerUser)->allows('createFor', $draft))->toBeTrue()
        ->and(Gate::forUser($this->otherUser)->allows('createFor', $draft))->toBeFalse()
        ->and(Gate::forUser($this->chefUser)->allows('createFor', $draft))->toBeFalse()
        ->and(Gate::forUser($this->chefUser)->allows('createFor', $chefDraft))->toBeTrue()
        ->and(Gate::forUser($this->bureau)->allows('createFor', $draft))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('createFor', $draft))->toBeFalse();
});

it('allows editing only a draft, by its owner or the Bureau', function (): void {
    $draft = TimesheetFixtures::timesheet($this->owner);

    expect($this->policy->update($this->ownerUser, $draft))->toBeTrue()
        ->and($this->policy->update($this->bureau, $draft))->toBeTrue()
        ->and($this->policy->update($this->chefUser, $draft))->toBeFalse()
        ->and($this->policy->update($this->otherUser, $draft))->toBeFalse();

    $lockedWeeks = ['2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30'];

    foreach ([TimesheetStatus::AControler, TimesheetStatus::ACorriger, TimesheetStatus::PretePourValidation, TimesheetStatus::Validee] as $index => $status) {
        $locked = TimesheetFixtures::timesheet($this->owner, week: $lockedWeeks[$index], status: $status);

        expect($this->policy->update($this->ownerUser, $locked))->toBeFalse()
            ->and($this->policy->update($this->bureau, $locked))->toBeFalse();
    }
});

it('allows submission only to the owner with at least one segment', function (): void {
    $empty = TimesheetFixtures::timesheet($this->owner);
    $filled = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09');
    TimesheetFixtures::segment($filled);

    expect($this->policy->submit($this->ownerUser, $empty))->toBeFalse()
        ->and($this->policy->submit($this->ownerUser, $filled))->toBeTrue()
        ->and($this->policy->submit($this->otherUser, $filled))->toBeFalse()
        ->and($this->policy->submit($this->bureau, $filled))->toBeFalse()
        ->and($this->policy->submit($this->chefUser, $filled))->toBeFalse();

    expect(Gate::forUser($this->ownerUser)->allows('submit', $filled))->toBeTrue()
        ->and(Gate::forUser($this->bureau)->allows('submit', $filled))->toBeFalse();
});

it('requires the control perimeter and a reason to return or prepare a timesheet', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);
    $outOfPerimeterChef = TimesheetFixtures::user(Role::ChefEquipe);

    expect($this->policy->requestCorrection($this->chefUser, $timesheet, 'Correction demandée'))->toBeTrue()
        ->and($this->policy->requestCorrection($this->chefUser, $timesheet))->toBeFalse()
        ->and($this->policy->requestCorrection($outOfPerimeterChef, $timesheet, 'Correction demandée'))->toBeFalse()
        ->and($this->policy->requestCorrection($this->bureau, $timesheet, 'Correction demandée'))->toBeTrue()
        ->and($this->policy->markReadyForValidation($this->chefUser, $timesheet, 'Contrôle satisfaisant'))->toBeTrue()
        ->and($this->policy->markReadyForValidation($this->chefUser, $timesheet, ''))->toBeFalse();
});

it('accepts the control reason through the Gate argument list', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(Gate::forUser($this->chefUser)->allows('requestCorrection', [$timesheet, 'Correction demandée']))->toBeTrue()
        ->and(Gate::forUser($this->chefUser)->allows('requestCorrection', $timesheet))->toBeFalse()
        ->and(Gate::forUser($this->chefUser)->allows('markReadyForValidation', [$timesheet, 'Contrôle satisfaisant']))->toBeTrue()
        ->and(Gate::forUser($this->chefUser)->allows('markReadyForValidation', [$timesheet, str_repeat('a', 501)]))->toBeFalse()
        ->and(Gate::forUser($this->bureau)->allows('requestCorrection', [$timesheet, '   ']))->toBeFalse();
});

it('allows a conducteur who leads the team to control', function (): void {
    $conducteurTeam = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $conducteurTeam, kind: TeamAssignmentKind::Temporary);
    TimesheetFixtures::lead($this->conducteurEmployee, $conducteurTeam);
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect($this->policy->requestCorrection($this->conducteurUser, $timesheet, 'Correction demandée'))->toBeTrue();
});

it('allows a chef to control their own timesheet but not to validate it', function (): void {
    TimesheetFixtures::assign($this->chefEmployee, $this->team);
    $timesheet = TimesheetFixtures::timesheet($this->chefEmployee, status: TimesheetStatus::AControler);

    expect($this->policy->requestCorrection($this->chefUser, $timesheet, 'Correction demandée'))->toBeTrue()
        ->and($this->policy->validate($this->chefUser, $timesheet))->toBeFalse();
});

it('allows correction resume to the owner and the Bureau only', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    expect($this->policy->resumeCorrection($this->ownerUser, $timesheet))->toBeTrue()
        ->and($this->policy->resumeCorrection($this->bureau, $timesheet))->toBeTrue()
        ->and($this->policy->resumeCorrection($this->chefUser, $timesheet))->toBeFalse()
        ->and($this->policy->resumeCorrection($this->otherUser, $timesheet))->toBeFalse();
});

it('forbids validation by the owner, a preparer, a controller or with incomplete audit', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);

    expect($this->policy->validate($this->bureau, $timesheet))->toBeFalse();

    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser);

    expect($this->policy->validate($this->ownerUser, $timesheet))->toBeFalse()
        ->and($this->policy->validate($this->chefUser, $timesheet))->toBeFalse()
        ->and($this->policy->validate($this->bureau, $timesheet))->toBeTrue();
});

it('refuses validation when a required audit event has no actor', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);

    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::auditWithoutActor($timesheet, AuditAction::TimesheetSubmitted);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');

    expect($this->policy->validate($this->bureau, $timesheet))->toBeFalse();
});

it('excludes a Bureau user who prepared or controlled the timesheet from validation', function (): void {
    $prepared = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($prepared, AuditAction::TimesheetCreated, $this->bureau);
    TimesheetFixtures::audit($prepared, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($prepared, AuditAction::TimesheetReadyForValidation, $this->chefUser);

    $controlled = TimesheetFixtures::timesheet($this->otherEmployee, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($controlled, AuditAction::TimesheetCreated, $this->otherUser);
    TimesheetFixtures::audit($controlled, AuditAction::TimesheetSubmitted, $this->otherUser);
    TimesheetFixtures::audit($controlled, AuditAction::TimesheetReadyForValidation, $this->bureau);

    expect($this->policy->validate($this->bureau, $prepared))->toBeFalse()
        ->and($this->policy->validate($this->bureau, $controlled))->toBeFalse();
});

it('requires the Bureau and a reason to reopen a validated timesheet', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    expect($this->policy->reopen($this->bureau, $timesheet, 'Réouverture demandée'))->toBeTrue()
        ->and($this->policy->reopen($this->bureau, $timesheet))->toBeFalse()
        ->and($this->policy->reopen($this->chefUser, $timesheet, 'Réouverture demandée'))->toBeFalse()
        ->and($this->policy->reopen($this->ownerUser, $timesheet, 'Réouverture demandée'))->toBeFalse()
        ->and($this->policy->reopen(TimesheetFixtures::user(Role::Admin), $timesheet, 'Réouverture demandée'))->toBeFalse();
});

it('requires the reopen reason through the Gate argument list', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Validee);

    expect(Gate::forUser($this->bureau)->allows('reopen', [$timesheet, 'Réouverture demandée']))->toBeTrue()
        ->and(Gate::forUser($this->bureau)->allows('reopen', $timesheet))->toBeFalse();
});

it('never allows a conducteur or the administrator to validate', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $this->ownerUser);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');

    expect($this->policy->validate($this->conducteurUser, $timesheet))->toBeFalse()
        ->and($this->policy->validate(TimesheetFixtures::user(Role::Admin), $timesheet))->toBeFalse();
});

it('allows only an active Bureau owner to self-validate with a valid reason', function (): void {
    $bureauEmployee = TimesheetFixtures::employee($this->bureau);
    $bureauSheet = TimesheetFixtures::timesheet($bureauEmployee, status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($bureauSheet, AuditAction::TimesheetCreated, $this->bureau);
    TimesheetFixtures::audit($bureauSheet, AuditAction::TimesheetSubmitted, $this->bureau);
    TimesheetFixtures::audit($bureauSheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');

    expect($this->policy->validate($this->bureau, $bureauSheet, '123456789'))->toBeFalse()
        ->and($this->policy->validate($this->bureau, $bureauSheet, '1234567890'))->toBeTrue();

    $inactiveBureau = TimesheetFixtures::user(Role::BureauDirection);
    $inactiveEmployee = TimesheetFixtures::employee($inactiveBureau);
    $inactiveSheet = TimesheetFixtures::timesheet($inactiveEmployee, week: '2026-11-09', status: TimesheetStatus::PretePourValidation);
    TimesheetFixtures::audit($inactiveSheet, AuditAction::TimesheetCreated, $inactiveBureau);
    TimesheetFixtures::audit($inactiveSheet, AuditAction::TimesheetSubmitted, $inactiveBureau);
    TimesheetFixtures::audit($inactiveSheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');
    $inactiveBureau->forceFill(['is_active' => false])->save();

    expect($this->policy->validate($inactiveBureau, $inactiveSheet, '1234567890'))->toBeFalse();
});

it('refuses self-validation to a chef, a conducteur and the administrator', function (): void {
    $admin = TimesheetFixtures::user(Role::Admin);
    $cases = [
        [$this->chefEmployee, '2026-11-02', $this->chefUser],
        [$this->conducteurEmployee, '2026-11-09', $this->conducteurUser],
        [TimesheetFixtures::employee($admin), '2026-11-16', $admin],
    ];

    foreach ($cases as [$employee, $week, $actor]) {
        $timesheet = TimesheetFixtures::timesheet($employee, week: $week, status: TimesheetStatus::PretePourValidation);
        TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $actor);
        TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $actor);
        TimesheetFixtures::audit($timesheet, AuditAction::TimesheetReadyForValidation, $this->chefUser, 'Contrôle satisfaisant');

        expect($this->policy->validate($actor, $timesheet, '1234567890'))->toBeFalse();
    }
});

it('requires at least ten characters for every reasoned control action', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect($this->policy->requestCorrection($this->chefUser, $timesheet, '123456789'))->toBeFalse()
        ->and($this->policy->requestCorrection($this->chefUser, $timesheet, '1234567890'))->toBeTrue()
        ->and($this->policy->markReadyForValidation($this->chefUser, $timesheet, '123456789'))->toBeFalse()
        ->and($this->policy->markReadyForValidation($this->chefUser, $timesheet, '1234567890'))->toBeTrue();
});

it('refuses every business ability to an inactive account', function (): void {
    $inactive = TimesheetFixtures::user(Role::BureauDirection);
    $inactive->forceFill(['is_active' => false])->save();

    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::PretePourValidation);

    expect($this->policy->viewAny($inactive))->toBeFalse()
        ->and($this->policy->view($inactive, $timesheet))->toBeFalse()
        ->and($this->policy->update($inactive, $timesheet))->toBeFalse()
        ->and($this->policy->requestCorrection($inactive, $timesheet, 'Correction demandée'))->toBeFalse()
        ->and($this->policy->markReadyForValidation($inactive, $timesheet, 'Contrôle satisfaisant'))->toBeFalse()
        ->and($this->policy->validate($inactive, $timesheet, 'Auto-validation motivée'))->toBeFalse();
});

it('refuses a chef outside the perimeter and allows the Bureau to view globally', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    $outOfPerimeterChef = TimesheetFixtures::user(Role::ChefEquipe);

    expect($this->policy->view($outOfPerimeterChef, $timesheet))->toBeFalse()
        ->and($this->policy->view($this->bureau, $timesheet))->toBeTrue();
});

it('never allows physical deletion', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->policy->delete($this->bureau, $timesheet))->toBeFalse()
        ->and($this->policy->deleteAny($this->bureau))->toBeFalse()
        ->and($this->policy->forceDelete($this->bureau, $timesheet))->toBeFalse()
        ->and($this->policy->forceDeleteAny($this->bureau))->toBeFalse();
});
