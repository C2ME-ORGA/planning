<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\PermissionException;
use App\Models\User;
use App\Services\Access\ApprovalRules;
use App\Services\Access\PermissionExceptionService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;

it('applies the approved role matrix on the server', function () {
    $expected = [
        Role::Admin->value => [Permission::AuditView],
        Role::BureauDirection->value => [
            Permission::AccountsView,
            Permission::AccountsCreate,
            Permission::AccountsUpdate,
            Permission::AccountsDeactivate,
            Permission::AccountsReactivate,
            Permission::AccountsResendActivation,
            Permission::RolesAssign,
            Permission::AuditView,
            Permission::PermissionExceptionsManage,
            Permission::PlanningManage,
            Permission::TimesheetValidate,
            Permission::WorkflowCorrect,
            Permission::WorkflowReopen,
            Permission::AbsenceManage,
        ],
        Role::Conducteur->value => [
            Permission::PlanningPropose,
            Permission::TimesheetControl,
            Permission::TimesheetOwn,
            Permission::AvailabilityViewPerimeter,
            Permission::AbsenceOwnView,
        ],
        Role::ChefEquipe->value => [
            Permission::PlanningPropose,
            Permission::TimesheetControl,
            Permission::TimesheetOwn,
            Permission::AvailabilityViewPerimeter,
            Permission::AbsenceOwnView,
        ],
        Role::Ouvrier->value => [
            Permission::TimesheetOwn,
            Permission::AbsenceOwnView,
        ],
    ];

    foreach (Role::cases() as $role) {
        $user = User::factory()->role($role)->create();

        foreach (Permission::cases() as $permission) {
            expect(Gate::forUser($user)->allows($permission->value))
                ->toBe(in_array($permission, $expected[$role->value], true), "{$role->value} / {$permission->value}");
        }
    }
});

it('never grants business rights to the technical administrator', function () {
    $admin = User::factory()->admin()->create();

    foreach (Permission::cases() as $permission) {
        if (in_array($permission, [Permission::AuditView, Permission::PermissionExceptionsManage], true)) {
            continue;
        }

        expect($admin->hasPermission($permission))->toBeFalse($permission->value);
    }

    expect($admin->hasPermission(Permission::AuditView))->toBeTrue()
        ->and($admin->can('create', User::class))->toBeFalse()
        ->and($admin->can('viewAny', User::class))->toBeFalse();
});

it('revokes every permission from an inactive account', function () {
    $user = User::factory()->bureauDirection()->inactive()->create();

    foreach (Permission::cases() as $permission) {
        expect($user->hasPermission($permission))->toBeFalse($permission->value);
    }
});

it('grants a temporary exception that expires and can be revoked', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $service = app(PermissionExceptionService::class);

    expect($target->hasPermission(Permission::PlanningPropose))->toBeFalse();

    $exception = $service->grant(
        $target,
        Permission::PlanningPropose,
        'Renfort temporaire sur un chantier.',
        now()->addDays(10),
        $actor,
    );

    expect($exception->isActive())->toBeTrue()
        ->and($target->fresh()->hasPermission(Permission::PlanningPropose))->toBeTrue();

    $this->travel(11)->days();

    expect($target->fresh()->hasPermission(Permission::PlanningPropose))->toBeFalse();

    $this->travelBack();

    $service->revoke($exception->fresh(), 'Fin du renfort temporaire.', $actor);

    expect($exception->fresh()->revoked_at)->not->toBeNull()
        ->and($target->fresh()->hasPermission(Permission::PlanningPropose))->toBeFalse()
        ->and(PermissionException::query()->where('user_id', $target->id)->count())->toBe(1);
});

it('refuses exceptions that would bypass the validated rules', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->conducteur()->create();
    $admin = User::factory()->admin()->create();
    $service = app(PermissionExceptionService::class);

    $grant = fn (User $user, Permission $permission, int $days = 10) => $service->grant(
        $user,
        $permission,
        'Justification suffisamment longue.',
        now()->addDays($days),
        $actor,
    );

    expect(fn () => $grant($target, Permission::TimesheetValidate))->toThrow(RuntimeException::class);
    expect(fn () => $grant($target, Permission::AccountsCreate))->toThrow(RuntimeException::class);
    expect(fn () => $grant($target, Permission::AuditView))->toThrow(RuntimeException::class);
    expect(fn () => $grant($admin, Permission::PlanningPropose))->toThrow(RuntimeException::class);
    expect(fn () => $grant($target, Permission::PlanningPropose, 400))->toThrow(RuntimeException::class);
    expect(fn () => $service->grant(
        $actor,
        Permission::PlanningPropose,
        'Justification suffisamment longue.',
        now()->addDays(3),
        $actor,
    ))->toThrow(RuntimeException::class);
});

it('requires a Bureau reason before approving its own production', function () {
    $rules = app(ApprovalRules::class);
    $bureau = User::factory()->bureauDirection()->create();
    $admin = User::factory()->admin()->create();
    $chef = User::factory()->chefEquipe()->create();
    $owner = User::factory()->ouvrier()->create();

    expect($rules->canApprove($bureau, $owner->id))->toBeTrue()
        ->and($rules->canApprove($bureau, $bureau->id))->toBeTrue()
        ->and($rules->canApprove($admin, $owner->id))->toBeFalse()
        ->and($rules->canApprove($chef, $owner->id))->toBeFalse()
        ->and($rules->canApprove($chef, $chef->id))->toBeFalse();

    expect(fn () => $rules->recordSelfApproval($bureau, $bureau->id, $owner, 'Court'))->toThrow(RuntimeException::class);
    expect(fn () => $rules->recordSelfApproval($chef, $chef->id, $owner, 'Auto-validation du chef.'))->toThrow(RuntimeException::class);

    $event = $rules->recordSelfApproval(
        $bureau,
        $bureau->id,
        $owner,
        'Validation de ma propre semaine après contrôle.',
    );

    expect($event->action)->toBe(AuditAction::SelfApprovalRecorded)
        ->and($event->reason)->toContain('propre semaine');
});

it('keeps the audit trail for exception grants and revocations', function () {
    $actor = User::factory()->bureauDirection()->create();
    $target = User::factory()->ouvrier()->create();
    $service = app(PermissionExceptionService::class);

    $exception = $service->grant(
        $target,
        Permission::AvailabilityViewPerimeter,
        'Consultation temporaire de la disponibilité.',
        now()->addDays(30),
        $actor,
    );

    $service->revoke($exception, 'Fin de la consultation temporaire.', $actor);

    $actions = app(AuditLogger::class);

    expect($actions->verify()->isValid)->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::PermissionExceptionGranted->value,
        'subject_id' => (string) $target->id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::PermissionExceptionRevoked->value,
        'subject_id' => (string) $target->id,
    ]);
});
