<?php

use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Services\Hours\ValidatedTimesheetSelector;
use Carbon\CarbonImmutable;
use Tests\Support\TimesheetFixtures;

function pc34Selector(): ValidatedTimesheetSelector
{
    return app(ValidatedTimesheetSelector::class);
}

function pc34SelectorPeriod(): array
{
    return [
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
        CarbonImmutable::parse('2026-12-15', 'Europe/Paris'),
    ];
}

it('includes only validated timesheets among all statuses', function (): void {
    $employee = TimesheetFixtures::employee();

    TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Brouillon);
    TimesheetFixtures::timesheet($employee, '2026-11-09', TimesheetStatus::AControler);
    TimesheetFixtures::timesheet($employee, '2026-11-16', TimesheetStatus::ACorriger);
    TimesheetFixtures::timesheet($employee, '2026-11-23', TimesheetStatus::PretePourValidation);
    $validated = TimesheetFixtures::timesheet($employee, '2026-11-30', TimesheetStatus::Validee);

    [$start, $end] = pc34SelectorPeriod();
    $result = pc34Selector()->forEmployee($employee, $start, $end);

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($validated))->toBeTrue();
});

it('excludes a validated timesheet immediately after reopening', function (): void {
    $employee = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Validee);

    [$start, $end] = pc34SelectorPeriod();

    expect(pc34Selector()->forEmployee($employee, $start, $end))->toHaveCount(1);

    $timesheet->update(['status' => TimesheetStatus::Brouillon]);

    expect(pc34Selector()->forEmployee($employee, $start, $end))->toHaveCount(0);
});

it('reintegrates a timesheet after a new validation', function (): void {
    $employee = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Validee);
    $timesheet->update(['status' => TimesheetStatus::Brouillon]);

    [$start, $end] = pc34SelectorPeriod();

    $timesheet->update(['status' => TimesheetStatus::Validee]);

    expect(pc34Selector()->forEmployee($employee, $start, $end))->toHaveCount(1);
});

it('never returns a validated timesheet from another employee', function (): void {
    $employee = TimesheetFixtures::employee();
    $other = TimesheetFixtures::employee();

    TimesheetFixtures::timesheet($other, '2026-11-02', TimesheetStatus::Validee);

    [$start, $end] = pc34SelectorPeriod();

    expect(pc34Selector()->forEmployee($employee, $start, $end))->toHaveCount(0);
});

it('applies the actor visibility scope to validated timesheets', function (): void {
    $employee = TimesheetFixtures::employee();
    TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Validee);

    [$start, $end] = pc34SelectorPeriod();

    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $ouvrier = TimesheetFixtures::user(Role::Ouvrier);

    expect(pc34Selector()->visibleFor($bureau, $employee, $start, $end))->toHaveCount(1)
        ->and(pc34Selector()->visibleFor($ouvrier, $employee, $start, $end))->toHaveCount(0);
});

it('loads only segments overlapping the requested window', function (): void {
    $employee = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Brouillon);

    TimesheetFixtures::segment($timesheet, '2026-11-02 08:00:00', '2026-11-02 12:00:00');
    TimesheetFixtures::segment($timesheet, '2026-11-03 08:00:00', '2026-11-03 12:00:00');
    $timesheet->update(['status' => TimesheetStatus::Validee]);

    $result = pc34Selector()->forEmployee(
        $employee,
        CarbonImmutable::parse('2026-11-03 00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-04 00:00', 'Europe/Paris'),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->segments)->toHaveCount(1)
        ->and($result->first()->segments->first()->start_at->format('Y-m-d'))->toBe('2026-11-03');
});

it('treats the end bound as a civil date (midday end excludes that day)', function (): void {
    $employee = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($employee, '2026-11-02', TimesheetStatus::Brouillon);

    TimesheetFixtures::segment($timesheet, '2026-11-02 08:00:00', '2026-11-02 12:00:00');
    TimesheetFixtures::segment($timesheet, '2026-11-03 08:00:00', '2026-11-03 12:00:00');
    $timesheet->update(['status' => TimesheetStatus::Validee]);

    // La borne de fin est en milieu de journée : elle est interprétée comme la
    // date civile 2026-11-03, exclusive. Le relevé du 3 novembre est donc exclu.
    $result = pc34Selector()->forEmployee(
        $employee,
        CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Paris'),
        CarbonImmutable::parse('2026-11-03 12:00', 'Europe/Paris'),
    );

    expect($result)->toHaveCount(1)
        ->and($result->first()->period_start->format('Y-m-d'))->toBe('2026-11-02')
        ->and($result->first()->segments)->toHaveCount(1)
        ->and($result->first()->segments->first()->start_at->format('Y-m-d'))->toBe('2026-11-02');
});
