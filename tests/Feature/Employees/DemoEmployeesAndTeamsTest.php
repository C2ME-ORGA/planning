<?php

use App\\Models\\Employee;
use App\\Models\\EmploymentPeriod;
use App\\Models\\Team;
use App\\Models\\TeamAssignment;
use App\\Models\\User;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;

function runDemoEmployeesAndTeams(): void
{
    expect(Artisan::call('planning:demo:employees-teams', ['--confirm' => true]))
        ->toBe(Command::SUCCESS);
}

it('creates the documented fictional employee and team dataset without accounts or audit events', function (): void {
    runDemoEmployeesAndTeams();

    $employees = Employee::query()->where('last_name', 'like', '%(démo PC1-53)')->get();
    $teams = Team::query()->where('name', 'like', '%(démo PC1-53)')->get();

    expect($employees)->toHaveCount(5)
        ->and($teams)->toHaveCount(2)
        ->and(EmploymentPeriod::query()->whereIn('employee_id', $employees->modelKeys())->count())->toBe(6)
        ->and(TeamAssignment::query()->whereIn('employee_id', $employees->modelKeys())->count())->toBe(4)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->count())->toBe(0);

    $rehire = $employees->firstWhere('first_name', 'Morgan');
    expect($rehire)->not->toBeNull()
        ->and($rehire->employmentPeriods()->orderBy('start_date')->count())->toBe(2);

    $temporary = TeamAssignment::query()
        ->whereIn('employee_id', $employees->modelKeys())
        ->where('kind', 'temporary')
        ->first();

    expect($temporary)->not->toBeNull()
        ->and($temporary->end_date)->not->toBeNull();
});

it('is idempotent and does not change records outside the demo dataset', function (): void {
    $existing = Employee::query()->create([
        'first_name' => 'Salarié',
        'last_name' => 'Réel hors démonstration',
    ]);

    runDemoEmployeesAndTeams();

    $employeeIds = Employee::query()->where('last_name', 'like', '%(démo PC1-53)')->orderBy('id')->pluck('id')->all();
    $teamIds = Team::query()->where('name', 'like', '%(démo PC1-53)')->orderBy('id')->pluck('id')->all();

    runDemoEmployeesAndTeams();

    expect(Employee::query()->where('last_name', 'like', '%(démo PC1-53)')->orderBy('id')->pluck('id')->all())
        ->toBe($employeeIds)
        ->and(Team::query()->where('name', 'like', '%(démo PC1-53)')->orderBy('id')->pluck('id')->all())
        ->toBe($teamIds)
        ->and(Employee::query()->where('last_name', 'like', '%(démo PC1-53)')->count())->toBe(5)
        ->and(Team::query()->where('name', 'like', '%(démo PC1-53)')->count())->toBe(2)
        ->and($existing->fresh()?->last_name)->toBe('Réel hors démonstration');
});

it('shows the target and refuses to write without explicit confirmation', function (): void {
    $this->artisan('planning:demo:employees-teams')
        ->expectsOutputToContain('Base ciblée')
        ->expectsOutputToContain('--confirm')
        ->assertFailed();

    expect(Employee::query()->count())->toBe(0)
        ->and(Team::query()->count())->toBe(0);
});
