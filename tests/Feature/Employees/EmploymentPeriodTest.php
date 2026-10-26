<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\EmploymentPeriodService;
use Illuminate\Support\Facades\DB;

it('refuses a period whose end precedes its start', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();

    expect(fn () => app(EmploymentPeriodService::class)->add(
        $employee,
        '2026-06-30',
        '2026-06-29',
        $actor,
    ))->toThrow(RuntimeException::class, 'ne peut pas précéder');
});

it('refuses partial, complete and identical employment overlaps', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $service = app(EmploymentPeriodService::class);

    $service->add($employee, '2026-01-01', '2026-06-30', $actor);

    foreach ([
        ['2026-06-01', '2026-07-15'],
        ['2025-12-01', '2026-07-31'],
        ['2026-01-01', '2026-06-30'],
    ] as $case) {
        expect(fn () => $service->add($employee, $case[0], $case[1], $actor))
            ->toThrow(RuntimeException::class, 'chevauche');
    }

    expect($service->add($employee, '2026-07-01', '2026-12-31', $actor)->start_date->toDateString())
        ->toBe('2026-07-01');
});

it('accepts strictly adjacent periods and treats open periods as infinite', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $service = app(EmploymentPeriodService::class);

    $service->add($employee, '2026-01-01', '2026-06-30', $actor);
    $service->add($employee, '2026-07-01', null, $actor);

    expect(fn () => $service->add($employee, '2026-07-02', '2026-08-31', $actor))
        ->toThrow(RuntimeException::class, 'chevauche');

    $other = Employee::factory()->create();
    $service->add($other, '2026-01-01', null, $actor);

    expect(fn () => $service->add($other, '2025-12-01', '2026-01-01', $actor))
        ->toThrow(RuntimeException::class, 'chevauche');

    $service->add($other, '2025-12-01', '2025-12-31', $actor);
    expect($other->fresh()->employmentPeriods()->count())->toBe(2);
});

it('creates a rehire as a new period without rewriting history or reactivating an account', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $inactive = User::factory()->ouvrier()->inactive()->create();
    app(EmployeeService::class)->linkAccount($employee, $inactive, $actor);

    $service = app(EmploymentPeriodService::class);
    $first = $service->add($employee, '2026-01-01', '2026-06-30', $actor);
    $second = $service->add($employee, '2026-07-01', '2026-12-31', $actor);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-06-30')
        ->and($second->start_date->toDateString())->toBe('2026-07-01')
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(2)
        ->and($inactive->fresh()->isActive())->toBeFalse();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeRehired->value,
        'subject_id' => (string) $employee->id,
    ]);
});

it('refuses period operations to unauthorized roles', function (Role $role) {
    $employee = Employee::factory()->create();
    $actor = User::factory()->role($role)->create();

    expect(fn () => app(EmploymentPeriodService::class)->add(
        $employee,
        '2026-01-01',
        '2026-06-30',
        $actor,
    ))->toThrow(RuntimeException::class);
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);

it('serializes concurrent period writes with a targeted lock', function () {
    config(['planning.employees.lock_timeout_seconds' => 1]);

    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $otherEmployee = Employee::factory()->create();
    $service = app(EmploymentPeriodService::class);

    config(['database.connections.employee_lock_probe' => config('database.connections.mysql')]);
    $probe = DB::connection('employee_lock_probe');
    $lockName = 'planning.employee.'.$employee->id;

    $acquired = $probe->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);

    expect((int) $acquired->acquired)->toBe(1);

    try {
        expect(fn () => $service->add($employee, '2026-01-01', '2026-06-30', $actor))
            ->toThrow(RuntimeException::class, 'autre opération');

        expect($employee->fresh()->employmentPeriods()->count())->toBe(0);

        // Le verrou est ciblé : un autre salarié n'est pas bloqué.
        $service->add($otherEmployee, '2026-01-01', '2026-06-30', $actor);
        expect($otherEmployee->fresh()->employmentPeriods()->count())->toBe(1);
    } finally {
        $probe->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        $probe->disconnect();
    }

    $period = $service->add($employee, '2026-01-01', '2026-06-30', $actor);

    expect($period->start_date->toDateString())->toBe('2026-01-01')
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(1);

    // Après sérialisation, une seconde période incompatible est rejetée par
    // la revalidation : les deux ne peuvent pas être validées.
    expect(fn () => $service->add($employee, '2026-06-30', '2026-12-31', $actor))
        ->toThrow(RuntimeException::class, 'chevauche');

    expect($employee->fresh()->employmentPeriods()->count())->toBe(1);
});
