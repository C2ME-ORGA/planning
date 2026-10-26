<?php

use App\Enums\Role;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('shows employee and team screens to the Bureau only', function () {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $team = Team::factory()->create();

    $this->actingAs($bureau)->get('/admin/employees')->assertOk()->assertSee('Salariés');
    $this->actingAs($bureau)->get('/admin/employees/'.$employee->id)->assertOk()->assertSee('Identité');
    $this->actingAs($bureau)->get('/admin/employees/create')->assertOk();
    $this->actingAs($bureau)->get('/admin/teams')->assertOk()->assertSee('Équipes');
    $this->actingAs($bureau)->get('/admin/teams/'.$team->id)->assertOk()->assertSee('Responsables principaux');
});

it('forbids employee and team screens to the technical administrator', function () {
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();
    $employee = Employee::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($admin)->get('/admin/employees')->assertForbidden();
    $this->actingAs($admin)->get('/admin/employees/'.$employee->id)->assertForbidden();
    $this->actingAs($admin)->get('/admin/teams')->assertForbidden();
    $this->actingAs($admin)->get('/admin/teams/'.$team->id)->assertForbidden();
});

it('adds an employment period through the Filament action with server validation', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $employee = Employee::factory()->create();

    $this->actingAs($bureau);

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])
        ->callAction('addEmploymentPeriod', data: [
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
        ])
        ->assertHasNoErrors();

    expect($employee->fresh()->employmentPeriods()->count())->toBe(1)
        ->and($employee->fresh()->employmentPeriods()->first()->start_date->toDateString())
        ->toBe('2026-01-01');
});

it('records an exit through the Filament action only after validation', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])
        ->callAction('recordExit', data: [
            'exit_date' => now()->toDateString(),
        ])
        ->assertHasNoErrors();

    expect($employee->fresh()->employmentPeriods()->first()->end_date->toDateString())
        ->toBe(now()->toDateString());
});

it('keeps the exit blocked through the Filament action while an assignment remains', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    TeamAssignment::factory()->create([
        'employee_id' => $employee->id,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    $this->actingAs($bureau);

    Livewire::test(ViewEmployee::class, ['record' => $employee->id])
        ->callAction('recordExit', data: [
            'exit_date' => now()->toDateString(),
        ]);

    expect($employee->fresh()->employmentPeriods()->first()->end_date)->toBeNull();
});

it('forbids employee and team screens to non-panel roles', function (Role $role) {
    $user = User::factory()->role($role)->withMultiFactorAuthentication()->create();

    $this->actingAs($user)->get('/admin/employees')->assertForbidden();
    $this->actingAs($user)->get('/admin/teams')->assertForbidden();
})->with([
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
