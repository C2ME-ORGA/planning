<?php

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Services\Hours\EmploymentPresence;
use Carbon\CarbonImmutable;

function pc34FeatureEmploymentPresence(): EmploymentPresence
{
    return app(EmploymentPresence::class);
}

it('ignores a partially loaded employment relation and uses all periods', function (): void {
    $employee = Employee::factory()->create();

    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-10',
    ]);
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-11-16',
        'end_date' => '2026-11-30',
    ]);

    // Simule un préchargement partiel qui ne contiendrait qu'une seule des
    // deux périodes. forEmployee recharge intégralement les périodes.
    $employee->setRelation('employmentPeriods', collect([]));

    $result = pc34FeatureEmploymentPresence()->forEmployee(
        $employee,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    // forEmployee recharge intégralement les périodes : 10 + 15 = 25 jours.
    expect($result['presence_days'])->toBe(25)
        ->and($result['has_employment'])->toBeTrue();
});

it('returns no presence when no employment period covers the month', function (): void {
    $employee = Employee::factory()->create();

    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
    ]);

    $result = pc34FeatureEmploymentPresence()->forEmployee(
        $employee,
        CarbonImmutable::parse('2026-11-01', 'Europe/Paris'),
    );

    expect($result['presence_days'])->toBe(0)
        ->and($result['has_employment'])->toBeFalse();
});
