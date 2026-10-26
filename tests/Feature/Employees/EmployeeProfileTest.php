<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeService;
use Illuminate\Support\Facades\Gate;

function employeeActor(): User
{
    return User::factory()->bureauDirection()->create();
}

it('creates an employee without an application account', function () {
    $actor = employeeActor();

    $employee = app(EmployeeService::class)->create('Camille', 'Martin', $actor);

    expect($employee->user_id)->toBeNull()
        ->and($employee->fullName())->toBe('Camille Martin');

    $this->assertDatabaseHas('employees', ['id' => $employee->id, 'user_id' => null]);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeCreated->value,
        'subject_id' => (string) $employee->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('links at most one account to one employee', function () {
    $actor = employeeActor();
    $service = app(EmployeeService::class);
    $employee = $service->create('Camille', 'Martin', $actor);
    $other = $service->create('Dominique', 'Durand', $actor);
    $user = User::factory()->chefEquipe()->create();

    $service->linkAccount($employee, $user, $actor);

    expect($employee->fresh()->user_id)->toBe($user->id);

    expect(fn () => $service->linkAccount($other, $user, $actor))
        ->toThrow(RuntimeException::class, 'déjà lié');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeAccountLinked->value,
        'subject_id' => (string) $employee->id,
    ]);
});

it('refuses to link a second account to the same employee', function () {
    $actor = employeeActor();
    $service = app(EmployeeService::class);
    $employee = $service->create('Camille', 'Martin', $actor);

    $service->linkAccount($employee, User::factory()->chefEquipe()->create(), $actor);

    expect(fn () => $service->linkAccount(
        $employee->fresh(),
        User::factory()->chefEquipe()->create(),
        $actor,
    ))->toThrow(RuntimeException::class, 'déjà un compte lié');
});

it('preserves the employee record and history when the account is deactivated', function () {
    $actor = employeeActor();
    $employee = app(EmployeeService::class)->create('Camille', 'Martin', $actor);
    $user = User::factory()->chefEquipe()->create();

    app(EmployeeService::class)->linkAccount($employee, $user, $actor);
    app(AccountAdministration::class)->deactivate($user, 'Fin de la collaboration.', $actor);

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue()
        ->and($employee->fresh()->user_id)->toBe($user->id)
        ->and($user->fresh()->isActive())->toBeFalse();
});

it('refuses employee operations to every role without the HR permission', function (Role $role) {
    $actor = User::factory()->role($role)->create();
    $bureau = employeeActor();

    expect(fn () => app(EmployeeService::class)->create('Camille', 'Martin', $actor))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(EmployeeService::class)->update(
        Employee::factory()->create(),
        'Camille',
        'Martin',
        $actor,
    ))->toThrow(RuntimeException::class);

    expect($actor->can('viewAny', Employee::class))->toBeFalse()
        ->and($actor->can('create', Employee::class))->toBeFalse()
        ->and(Gate::forUser($actor)->allows(Permission::EmployeesManage->value))->toBeFalse();

    expect($bureau->can('viewAny', Employee::class))->toBeTrue()
        ->and($bureau->can('create', Employee::class))->toBeTrue();
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
