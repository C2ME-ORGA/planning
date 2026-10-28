<?php

use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Services\Timesheets\TimesheetPerimeter;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->perimeter = app(TimesheetPerimeter::class);
    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chefEmployee = TimesheetFixtures::employee($this->chefUser);
    $this->team = TimesheetFixtures::team();
});

it('includes a primary assignment and its leader for the week', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chefEmployee, $this->team);
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([$this->chefEmployee->id])
        ->and($this->perimeter->canControl($this->chefUser, $timesheet))->toBeTrue();
});

it('includes a temporary assignment covering only part of the week', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team, '2026-11-05', '2026-11-05', TeamAssignmentKind::Temporary);
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-11-05', '2026-11-05');
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([$this->chefEmployee->id]);
});

it('includes both leaders when the team or the leadership changes during the week', function (): void {
    $otherTeam = TimesheetFixtures::team();
    $otherChef = TimesheetFixtures::employee(TimesheetFixtures::user(Role::ChefEquipe));

    TimesheetFixtures::assign($this->owner, $this->team, '2026-01-01', '2026-11-03');
    TimesheetFixtures::assign($this->owner, $otherTeam, '2026-11-04', null);
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-01-01', '2026-11-04');
    TimesheetFixtures::lead($otherChef, $otherTeam, '2026-11-05', null);
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    $leaders = $this->perimeter->leaderEmployeeIdsFor($timesheet);

    expect($leaders)->toContain($this->chefEmployee->id, $otherChef->id)
        ->and($leaders)->toHaveCount(2);
});

it('keeps a responsibility that ended normally after covering part of the week', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-01-01', '2026-11-05');
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([$this->chefEmployee->id]);
});

it('excludes a responsibility or assignment ended before the week', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team, '2026-01-01', '2026-10-31');
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-01-01', '2026-10-31');
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([]);
});

it('excludes cancelled assignments and cancelled responsibilities', function (): void {
    $assignment = TimesheetFixtures::assign($this->owner, $this->team, '2026-11-02', '2026-11-08');
    $leadership = TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-11-02', '2026-11-08');

    $assignment->forceFill(['cancelled_at' => now()])->save();
    $leadership->forceFill(['cancelled_at' => now()])->save();
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([]);
});

it('returns every admissible leader without choosing a priority', function (): void {
    $otherTeam = TimesheetFixtures::team();
    $otherChef = TimesheetFixtures::employee(TimesheetFixtures::user(Role::ChefEquipe));

    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::assign($this->owner, $otherTeam, kind: TeamAssignmentKind::Temporary);
    TimesheetFixtures::lead($this->chefEmployee, $this->team);
    TimesheetFixtures::lead($otherChef, $otherTeam);
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    $leaders = $this->perimeter->leaderEmployeeIdsFor($timesheet);

    expect($leaders)->toContain($this->chefEmployee->id, $otherChef->id)
        ->and($leaders)->toHaveCount(2);
});

it('returns no leader when the team has no responsibility', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team);
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([])
        ->and($this->perimeter->canControl($this->chefUser, $timesheet))->toBeFalse();
});

it('refuses a chef who leads another team', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chefEmployee, TimesheetFixtures::team());
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->canControl($this->chefUser, $timesheet))->toBeFalse();
});

it('allows a chef to control their own timesheet when they lead the team', function (): void {
    TimesheetFixtures::assign($this->chefEmployee, $this->team);
    TimesheetFixtures::lead($this->chefEmployee, $this->team);
    $timesheet = TimesheetFixtures::timesheet($this->chefEmployee);

    expect($this->perimeter->canControl($this->chefUser, $timesheet))->toBeTrue();
});

it('allows a conducteur who leads the team to control', function (): void {
    $conducteurUser = TimesheetFixtures::user(Role::Conducteur);
    $conducteur = TimesheetFixtures::employee($conducteurUser);

    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($conducteur, $this->team);
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->canControl($conducteurUser, $timesheet))->toBeTrue();
});

it('treats the period boundaries as inclusive', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team, '2026-11-08', '2026-11-08');
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-11-08', '2026-11-08');
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([$this->chefEmployee->id]);
});

it('requires the assignment and the leadership to overlap, not merely the week', function (): void {
    TimesheetFixtures::assign($this->owner, $this->team, '2026-11-08', '2026-11-08');
    TimesheetFixtures::lead($this->chefEmployee, $this->team, '2026-11-02', '2026-11-02');
    $timesheet = TimesheetFixtures::timesheet($this->owner);

    expect($this->perimeter->leaderEmployeeIdsFor($timesheet))->toBe([]);
});
