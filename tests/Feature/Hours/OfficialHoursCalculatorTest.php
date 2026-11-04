<?php

use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Hours\OfficialHoursCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TimesheetFixtures;

function pc34OfficialTimesheet(Employee $employee, array $segments): Timesheet
{
    $timesheet = TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Brouillon);

    foreach ($segments as $segment) {
        TimesheetFixtures::segment($timesheet, $segment[0], $segment[1]);
    }

    $timesheet->update(['status' => TimesheetStatus::Validee]);

    return $timesheet->fresh(['segments']);
}

it('computes weekly overtime and monthly aggregates from validated timesheets only', function (): void {
    $employee = TimesheetFixtures::employee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
        ['2026-11-03 08:00:00', '2026-11-03 16:00:00'],
        ['2026-11-04 08:00:00', '2026-11-04 16:00:00'],
        ['2026-11-05 08:00:00', '2026-11-05 16:00:00'],
        ['2026-11-06 08:00:00', '2026-11-06 16:00:00'],
    ]);

    $calculator = app(OfficialHoursCalculator::class);

    $weekly = $calculator->weeklyForEmployee($employee, '2026-11-02');
    $monthly = $calculator->monthlyForEmployee($employee, '2026-11-01');

    expect($weekly['effective_minutes'])->toBe(2400)
        ->and($weekly['overtime_minutes'])->toBe(60)
        ->and($weekly['deficit_minutes'])->toBe(0)
        ->and($monthly['effective_minutes'])->toBe(2400)
        ->and($monthly['reference_minutes'])->toBe(10140)
        ->and($monthly['deficit_minutes'])->toBe(7740)
        ->and($monthly['by_category']['travail_effectif']['minutes'])->toBe(2400);
});

it('excludes a reopened timesheet immediately and reintegrates it after revalidation', function (): void {
    $employee = TimesheetFixtures::employee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    $timesheet = pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
    ]);

    $calculator = app(OfficialHoursCalculator::class);

    expect($calculator->weeklyForEmployee($employee, '2026-11-02')['effective_minutes'])->toBe(480);

    $timesheet->update(['status' => TimesheetStatus::Brouillon]);

    $reopenedWeekly = $calculator->weeklyForEmployee($employee, '2026-11-02');
    $reopenedMonthly = $calculator->monthlyForEmployee($employee, '2026-11-01');

    expect($reopenedWeekly['effective_minutes'])->toBe(0)
        ->and($reopenedWeekly['deficit_minutes'])->toBe(2340)
        ->and($reopenedMonthly['effective_minutes'])->toBe(0)
        ->and($reopenedMonthly['deficit_minutes'])->toBe(10140);

    $timesheet->update(['status' => TimesheetStatus::Validee]);

    expect($calculator->weeklyForEmployee($employee, '2026-11-02')['effective_minutes'])->toBe(480);
});

it('does not write segments or audit events while calculating', function (): void {
    $employee = TimesheetFixtures::employee();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
    ]);

    $segmentCount = TimesheetSegment::query()->count();
    $auditCount = AuditEvent::query()->count();

    $calculator = app(OfficialHoursCalculator::class);
    $calculator->weeklyForEmployee($employee, '2026-11-02');
    $calculator->monthlyForEmployee($employee, '2026-11-01');

    expect(TimesheetSegment::query()->count())->toBe($segmentCount)
        ->and(AuditEvent::query()->count())->toBe($auditCount);
});

it('allows an authorized actor to compute weekly hours via weeklyForActor', function (): void {
    $employee = TimesheetFixtures::employee();
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
    ]);

    $calculator = app(OfficialHoursCalculator::class);

    expect($calculator->weeklyForActor($bureau, $employee, '2026-11-02')['effective_minutes'])
        ->toBe(480);
});

it('allows an authorized actor to compute monthly hours via monthlyForActor', function (): void {
    $employee = TimesheetFixtures::employee();
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
    ]);

    $calculator = app(OfficialHoursCalculator::class);

    expect($calculator->monthlyForActor($bureau, $employee, '2026-11-01')['effective_minutes'])
        ->toBe(480);
});

it('refuses an actor without timesheet view permission', function (): void {
    $employee = TimesheetFixtures::employee();
    $admin = TimesheetFixtures::user(Role::Admin);

    $calculator = app(OfficialHoursCalculator::class);

    expect(fn () => $calculator->weeklyForActor($admin, $employee, '2026-11-02'))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $calculator->monthlyForActor($admin, $employee, '2026-11-01'))
        ->toThrow(AuthorizationException::class);
});

it('returns no result for an actor outside the employee perimeter', function (): void {
    $employee = TimesheetFixtures::employee();
    $otherEmployee = TimesheetFixtures::employee();
    $ouvrier = TimesheetFixtures::user(Role::Ouvrier);
    // L'ouvrier est lié à son propre employé via l'user_id de TimesheetFixtures::employee(),
    // pas à $employee. Le périmètre TimesheetOwn exclut donc $employee.
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    pc34OfficialTimesheet($employee, [
        ['2026-11-02 08:00:00', '2026-11-02 16:00:00'],
    ]);

    $calculator = app(OfficialHoursCalculator::class);

    expect($calculator->weeklyForActor($ouvrier, $employee, '2026-11-02')['effective_minutes'])
        ->toBe(0);
});
