<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Enums\TimesheetStatus;
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

it('allows draft creation only to the owner or the Bureau', function (): void {
    expect($this->policy->create($this->ownerUser, $this->owner))->toBeTrue()
        ->and($this->policy->create($this->otherUser, $this->owner))->toBeFalse()
        ->and($this->policy->create($this->chefUser, $this->owner))->toBeFalse()
        ->and($this->policy->create($this->chefUser, $this->chefEmployee))->toBeTrue()
        ->and($this->policy->create($this->bureau, $this->owner))->toBeTrue()
        ->and($this->policy->create(TimesheetFixtures::user(Role::Admin), $this->owner))->toBeFalse();
});

it('allows editing only a draft, by its owner or the Bureau', function (): void {
    $draft = TimesheetFixtures::timesheet($this->owner);

    expect($this->policy->update($this->ownerUser, $draft))->toBeTrue()
        ->and($this->policy->update($this->bureau, $draft))->toBeTrue()
        ->and($this->policy->update($this->chefUser, $draft))->toBeFalse()
        ->and($this->policy->update($this->otherUser, $draft))->toBeFalse();

    foreach ([TimesheetStatus::AControler, TimesheetStatus::ACorriger, TimesheetStatus::PretePourValidation, TimesheetStatus::Validee] as $status) {
        $locked = TimesheetFixtures::timesheet($this->owner, status: $status);

        expect($this->policy->update($this->ownerUser, $locked))->toBeFalse()
            ->and($this->policy->update($this->bureau, $locked))->toBeFalse();
    }
});

it('allows submission only to the owner with at least one segment', function (): void {
    $empty = TimesheetFixtures::timesheet($this->owner);
    $filled = TimesheetFixtures::timesheet($this->owner);
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

it('never allows physical deletion', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->policy->delete($this->bureau, $timesheet))->toBeFalse()
        ->and($this->policy->deleteAny($this->bureau))->toBeFalse()
        ->and($this->policy->forceDelete($this->bureau, $timesheet))->toBeFalse()
        ->and($this->policy->forceDeleteAny($this->bureau))->toBeFalse();
});
