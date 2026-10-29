<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Services\Timesheets\TimesheetService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->service = app(TimesheetService::class);
});

it('opens a draft week for the employee owner', function (): void {
    $user = TimesheetFixtures::user(Role::Ouvrier);
    $employee = TimesheetFixtures::employee($user);

    $timesheet = $this->service->openFor($employee, '2026-11-02', $user);

    expect($timesheet->status)->toBe(TimesheetStatus::Brouillon)
        ->and($timesheet->period_start?->toDateString())->toBe('2026-11-02')
        ->and($timesheet->period_end?->toDateString())->toBe('2026-11-08');

    expect(AuditEvent::query()
        ->where('action', AuditAction::TimesheetCreated->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->where('actor_id', $user->getKey())
        ->exists())->toBeTrue();
});

it('normalizes the week to monday without trusting client dates', function (): void {
    $user = TimesheetFixtures::user(Role::Ouvrier);
    $employee = TimesheetFixtures::employee($user);

    $timesheet = $this->service->openFor($employee, '2026-11-05', $user);

    expect($timesheet->period_start?->toDateString())->toBe('2026-11-02')
        ->and($timesheet->period_end?->toDateString())->toBe('2026-11-08');
});

it('lets the Bureau open a draft for an employee without a linked account', function (): void {
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $employee = TimesheetFixtures::employee();

    expect($employee->user_id)->toBeNull();

    $timesheet = $this->service->openFor($employee, '2026-11-02', $bureau);

    expect($timesheet->employee_id)->toBe($employee->getKey())
        ->and($timesheet->status)->toBe(TimesheetStatus::Brouillon);
});

it('refuses creation by another employee or the technical administrator', function (): void {
    $owner = TimesheetFixtures::user(Role::Ouvrier);
    $employee = TimesheetFixtures::employee($owner);
    $other = TimesheetFixtures::user(Role::Ouvrier);
    $admin = TimesheetFixtures::user(Role::Admin);

    expect(fn (): Timesheet => $this->service->openFor($employee, '2026-11-02', $other))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->openFor($employee, '2026-11-02', $admin))
        ->toThrow(AuthorizationException::class);

    expect(Timesheet::query()->count())->toBe(0);
});

it('returns an existing week only when the actor may view it', function (): void {
    $owner = TimesheetFixtures::user(Role::Ouvrier);
    $employee = TimesheetFixtures::employee($owner);
    $other = TimesheetFixtures::user(Role::Ouvrier);
    $existing = TimesheetFixtures::timesheet($employee, week: '2026-11-02', status: TimesheetStatus::ACorriger);

    $returned = $this->service->openFor($employee, '2026-11-02', $owner);

    expect($returned->getKey())->toBe($existing->getKey())
        ->and($returned->status)->toBe(TimesheetStatus::ACorriger)
        ->and(Timesheet::query()->count())->toBe(1);

    expect(fn (): Timesheet => $this->service->openFor($employee, '2026-11-02', $other))
        ->toThrow(AuthorizationException::class);

    expect(Timesheet::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetCreated->value)->count())->toBe(0);
});

it('returns an existing week to the Bureau without creating a duplicate', function (): void {
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $employee = TimesheetFixtures::employee();
    $existing = TimesheetFixtures::timesheet($employee, week: '2026-11-02');

    $returned = $this->service->openFor($employee, '2026-11-02', $bureau);

    expect($returned->getKey())->toBe($existing->getKey())
        ->and(Timesheet::query()->count())->toBe(1);
});

it('rolls back the creation when the audit write fails', function (): void {
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $employee = TimesheetFixtures::employee();

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->openFor($employee, '2026-11-02', $bureau))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(Timesheet::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
