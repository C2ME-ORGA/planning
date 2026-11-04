<?php

use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Timesheet;
use App\Services\Hours\MonthlyHoursAggregator;
use App\Services\Hours\MonthlyReferenceChange;
use App\Services\Hours\MonthlyReferenceProvider;
use Carbon\CarbonImmutable;
use Tests\Support\ArrayMonthlyReferenceProvider;
use Tests\Support\TimesheetFixtures;

function pc34MonthlyAggregator(): MonthlyHoursAggregator
{
    return app(MonthlyHoursAggregator::class);
}

/**
 * @param  array<int, array{0: string, 1: string, 2?: TimeCategory}>  $segments
 */
function pc34ValidatedTimesheet(Employee $employee, string $week, array $segments): Timesheet
{
    $timesheet = TimesheetFixtures::timesheet($employee, $week, TimesheetStatus::Brouillon);

    foreach ($segments as $segment) {
        TimesheetFixtures::segment(
            $timesheet,
            $segment[0],
            $segment[1],
            $segment[2] ?? TimeCategory::TravailEffectif,
        );
    }

    $timesheet->update(['status' => TimesheetStatus::Validee]);

    return $timesheet->fresh(['segments']);
}

function pc34EmploymentPeriod(Employee $employee, string $start, ?string $end = null): EmploymentPeriod
{
    return EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => $start,
        'end_date' => $end,
    ]);
}

it('computes monthly effective minutes and the standard reference', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-11-02', [
        ['2026-11-02 08:00:00', '2026-11-02 12:00:00'],
    ]);

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['month'])->toBe('2026-11')
        ->and($result['effective_minutes'])->toBe(240)
        ->and($result['presence_days'])->toBe(30)
        ->and($result['days_in_month'])->toBe(30)
        ->and($result['reference_applicable'])->toBeTrue()
        ->and($result['reference_minutes'])->toBe(10140)
        ->and($result['reference_prorated'])->toBeFalse()
        ->and($result['difference_minutes'])->toBe(-9900)
        ->and($result['credit_candidate_minutes'])->toBe(0)
        ->and($result['deficit_minutes'])->toBe(9900);
});

it('deduplicates overlapping effective segments in the monthly total', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-11-02', [
        ['2026-11-02 08:00:00', '2026-11-02 12:00:00'],
        ['2026-11-02 10:00:00', '2026-11-02 14:00:00'],
    ]);

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['effective_minutes'])->toBe(360)
        ->and($result['overlaps'])->toHaveCount(1)
        ->and($result['by_day'])->toBe(['2026-11-02' => 360]);
});

it('tracks each category separately without merging across categories', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-11-02', [
        ['2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::TravailEffectif],
        ['2026-11-02 08:00:00', '2026-11-02 12:00:00', TimeCategory::Pause],
        ['2026-11-02 12:00:00', '2026-11-02 14:00:00', TimeCategory::Trajet],
    ]);

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['effective_minutes'])->toBe(240)
        ->and($result['by_category']['travail_effectif']['minutes'])->toBe(240)
        ->and($result['by_category']['pause']['minutes'])->toBe(240)
        ->and($result['by_category']['trajet']['minutes'])->toBe(120)
        ->and($result['by_category']['formation']['minutes'])->toBe(0)
        ->and($result['by_category']['autre']['minutes'])->toBe(0);
});

it('splits a week crossing two months into each civil month', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-10-26', [
        ['2026-10-31 22:00:00', '2026-11-01 02:00:00'],
    ]);

    $october = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-10-01', 'Europe/Paris'),
    );
    $november = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($october['effective_minutes'])->toBe(120)
        ->and($october['by_day'])->toBe(['2026-10-31' => 120])
        ->and($november['effective_minutes'])->toBe(120)
        ->and($november['by_day'])->toBe(['2026-11-01' => 120]);
});

it('prorates the reference for an entry in the middle of the month', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-11-16');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-11-16', [
        ['2026-11-16 08:00:00', '2026-11-16 12:00:00'],
    ]);

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['presence_days'])->toBe(15)
        ->and($result['reference_minutes'])->toBe(5070)
        ->and($result['reference_prorated'])->toBeTrue()
        ->and($result['deficit_minutes'])->toBe(4830);
});

it('prorates the reference for an exit in the middle of the month', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01', '2026-11-15');

    $timesheet = pc34ValidatedTimesheet($employee, '2026-11-09', [
        ['2026-11-09 08:00:00', '2026-11-09 12:00:00'],
    ]);

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        $timesheet->segments,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['presence_days'])->toBe(15)
        ->and($result['reference_minutes'])->toBe(5070);
});

it('generates no reference or counter when the employee has no employment period', function (): void {
    $employee = TimesheetFixtures::employee();

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        [],
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['reference_applicable'])->toBeFalse()
        ->and($result['reference_base_minutes'])->toBeNull()
        ->and($result['reference_minutes'])->toBeNull()
        ->and($result['difference_minutes'])->toBeNull()
        ->and($result['credit_candidate_minutes'])->toBeNull()
        ->and($result['deficit_minutes'])->toBeNull()
        ->and($result['presence_days'])->toBe(0);
});

it('supports months of 28, 29, 30 and 31 days', function (string $month, int $days): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, $month.'-01');

    $result = pc34MonthlyAggregator()->calculate(
        $employee,
        [],
        CarbonImmutable::parse($month.'-01', 'Europe/Paris'),
    );

    expect($result['days_in_month'])->toBe($days)
        ->and($result['presence_days'])->toBe($days)
        ->and($result['reference_minutes'])->toBe(10140);
})->with([
    'février 2026' => ['2026-02', 28],
    'février 2028 bissextile' => ['2028-02', 29],
    'avril 2026' => ['2026-04', 30],
    'mai 2026' => ['2026-05', 31],
]);

it('applies an individual reference resolved by the provider', function (): void {
    $employee = TimesheetFixtures::employee();
    pc34EmploymentPeriod($employee, '2026-01-01');

    app()->instance(MonthlyReferenceProvider::class, new ArrayMonthlyReferenceProvider([
        (int) $employee->getKey() => [
            MonthlyReferenceChange::forMonth('2026-11-01', 9000),
        ],
    ]));

    $result = app(MonthlyHoursAggregator::class)->calculate(
        $employee,
        [],
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['reference_base_minutes'])->toBe(9000)
        ->and($result['reference_minutes'])->toBe(9000);
});
