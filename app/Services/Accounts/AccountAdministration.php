<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeMutationLock;
use App\Services\Employees\TeamLeadershipService;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AccountAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRevoker $sessions,
        private readonly TeamLeadershipService $leaderships,
        private readonly EmployeeMutationLock $employeeLocks,
    ) {}

    public function deactivate(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsDeactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se désactiver lui-même.');

        try {
            $this->withEmployeeLock($target, function () use ($target, $reason, $actor): void {
                DB::transaction(function () use ($target, $reason, $actor): void {
                    /** @var User $target */
                    $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                    if (! $target->isActive()) {
                        throw new RuntimeException('Ce compte est déjà désactivé.');
                    }

                    $this->assertNotLastBureauDirection($target);
                    $this->leaderships->assertDeactivationAllowed($target);
                    $this->applyDeactivation($target, $reason, $actor);
                });
            });
        } catch (SessionRevocationException $exception) {
            $this->recordRevocationFailure($exception, $target, $reason, $actor);

            throw $exception;
        }
    }

    /**
     * Désactivation demandée explicitement lors de la sortie d'un salarié.
     *
     * Règle des dates inclusives : la désactivation est immédiate, sa date
     * effective est donc le jour courant. Une responsabilité qui couvre encore
     * cette date (end_date nulle ou >= aujourd'hui) interdit la désactivation —
     * y compris une responsabilité se terminant à la date de sortie, la période
     * étant inclusive. La sortie peut alors être enregistrée sans désactivation,
     * ou intervenir après clôture ou réaffectation explicite de la responsabilité.
     *
     * @return bool Vrai si le compte a effectivement été désactivé.
     */
    public function deactivateForEmployeeExit(User $target, string $reason, User $actor): bool
    {
        $this->assertCan($actor, Permission::AccountsDeactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se désactiver lui-même.');

        if (! $target->isActive()) {
            return false;
        }

        $this->withEmployeeLock($target, function () use ($target, $reason, $actor): void {
            DB::transaction(function () use ($target, $reason, $actor): void {
                /** @var User $target */
                $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                if (! $target->isActive()) {
                    throw new RuntimeException('Ce compte est déjà désactivé.');
                }

                $this->assertNotLastBureauDirection($target);

                // Dates inclusives : aucun compte responsable ne peut être
                // désactivé alors qu'une responsabilité couvre encore la date
                // effective de désactivation.
                $this->leaderships->assertDeactivationAllowed($target);

                $this->applyDeactivation($target, $reason, $actor);
            });
        });

        return true;
    }

    public function reactivate(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsReactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se réactiver lui-même.');

        try {
            $this->withEmployeeLock($target, function () use ($target, $reason, $actor): void {
                DB::transaction(function () use ($target, $reason, $actor): void {
                    /** @var User $target */
                    $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                    if ($target->isActive()) {
                        throw new RuntimeException('Ce compte est déjà actif.');
                    }

                    $target->forceFill([
                        'is_active' => true,
                        'deactivated_at' => null,
                        'deactivation_reason' => null,
                    ])->save();

                    $result = $this->sessions->revoke($target);

                    $this->audit->record(
                        AuditAction::AccountReactivated,
                        $target,
                        ['is_active' => ['old' => false, 'new' => true]] + $this->sessionAuditChanges($result),
                        $reason,
                        $actor,
                    );
                });
            });
        } catch (SessionRevocationException $exception) {
            $this->recordRevocationFailure($exception, $target, $reason, $actor);

            throw $exception;
        }
    }

    public function changeRole(User $target, Role $role, User $actor): void
    {
        $this->assertCan($actor, Permission::RolesAssign);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas modifier son propre rôle.');

        if ($target->role === $role) {
            return;
        }

        try {
            $this->withEmployeeLock($target, function () use ($target, $role, $actor): void {
                DB::transaction(function () use ($target, $role, $actor): void {
                    /** @var User $target */
                    $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                    if ($target->role === $role) {
                        return;
                    }

                    if ($target->isBureauDirection() && $role !== Role::BureauDirection) {
                        $this->assertNotLastBureauDirection($target);
                    }

                    // Une responsabilité d'équipe non terminée exige un rôle compatible.
                    $this->leaderships->assertRoleRemovalAllowed($target, $role);

                    $target->forceFill(['role' => $role])->save();

                    // Le journal du changement de rôle est écrit par UserObserver.
                    // L'événement ci-dessous trace explicitement la révocation
                    // des sessions et son résultat physique.
                    $result = $this->sessions->revoke($target);

                    $this->audit->record(
                        AuditAction::SessionsRevoked,
                        $target,
                        $this->sessionAuditChanges($result) + [
                            'cause' => 'account.role_changed',
                            'role' => $role->value,
                        ],
                        'Révocation des sessions après changement de rôle.',
                        $actor,
                    );
                });
            });
        } catch (SessionRevocationException $exception) {
            $this->recordRevocationFailure(
                $exception,
                $target,
                'Échec de la révocation des sessions après changement de rôle.',
                $actor,
            );

            throw $exception;
        }
    }

    public function resetMultiFactor(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        try {
            DB::transaction(function () use ($target, $reason, $actor): void {
                $target->forceFill([
                    'app_authentication_secret' => null,
                    'app_authentication_recovery_codes' => null,
                ])->save();

                $result = $this->sessions->revoke($target);

                $this->audit->record(
                    AuditAction::MfaReset,
                    $target,
                    $this->sessionAuditChanges($result),
                    $reason,
                    $actor,
                );
            });
        } catch (SessionRevocationException $exception) {
            $this->recordRevocationFailure($exception, $target, $reason, $actor);

            throw $exception;
        }
    }

    public function revokeSessions(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        try {
            $result = $this->sessions->revoke($target);
        } catch (SessionRevocationException $exception) {
            $this->recordRevocationFailure($exception, $target, $reason, $actor);

            throw $exception;
        }

        $this->audit->record(
            AuditAction::SessionsRevoked,
            $target,
            $this->sessionAuditChanges($result),
            $reason,
            $actor,
        );
    }

    /**
     * Garantit qu'il reste toujours au moins un compte Bureau / Direction
     * actif : sans lui, plus aucune opération métier ne serait possible.
     */
    public function assertNotLastBureauDirection(User $target): void
    {
        if (! $target->isBureauDirection() || ! $target->isActive()) {
            return;
        }

        $others = User::query()
            ->active()
            ->bureauDirection()
            ->whereKeyNot($target->getKey())
            ->exists();

        if (! $others) {
            throw new RuntimeException('Le dernier compte Bureau / Direction actif ne peut pas être retiré.');
        }
    }

    public function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));

        if ($length < 10) {
            throw new RuntimeException('Un motif d’au moins 10 caractères est requis.');
        }

        if ($length > 500) {
            throw new RuntimeException('Le motif ne peut pas dépasser 500 caractères.');
        }
    }

    private function applyDeactivation(User $target, string $reason, User $actor): void
    {
        $target->forceFill([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ])->save();

        $result = $this->sessions->revoke($target);

        $this->audit->record(
            AuditAction::AccountDeactivated,
            $target,
            ['is_active' => ['old' => true, 'new' => false]] + $this->sessionAuditChanges($result),
            $reason,
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionAuditChanges(SessionRevocationResult $result): array
    {
        return [
            'sessions_revoked' => $result->deletedSessions,
            'session_scope' => $result->scope,
            'session_generation' => $result->generation,
            'session_storage' => $result->sessionDriver,
            'session_cleanup_required' => $result->physicalCleanupRequired,
            'session_cleanup_state' => $result->physicalCleanupState()->value,
            'session_cleanup_complete' => $result->isPhysicalCleanupComplete(),
            'session_cleanup_failed' => $result->hasPhysicalCleanupFailure(),
            'session_cleanup_error' => $result->physicalError,
        ];
    }

    private function recordRevocationFailure(
        SessionRevocationException $exception,
        ?User $target,
        ?string $reason,
        ?User $actor,
    ): void {
        try {
            $this->audit->record(
                AuditAction::SessionRevocationFailed,
                $target,
                [
                    'scope' => $exception->scope,
                    'step' => $exception->step,
                    'error_class' => $exception->getPrevious() === null
                        ? null
                        : $exception->getPrevious()::class,
                ],
                $reason,
                $actor,
            );
        } catch (Throwable $auditFailure) {
            // L'échec initial reste prioritaire. L'absence d'audit est
            // signalée sans masquer l'exception de sécurité d'origine.
            report($auditFailure);
        }
    }

    private function withEmployeeLock(User $target, Closure $callback): void
    {
        $employee = Employee::query()->where('user_id', $target->getKey())->first();

        if ($employee instanceof Employee) {
            $this->employeeLocks->run($employee, $callback);

            return;
        }

        $callback();
    }

    private function assertNotSelf(User $target, User $actor, string $message): void
    {
        if ($target->is($actor)) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Vérification serveur redondante avec les policies : un futur appelant
     * (commande, action métier, test) ne peut pas contourner la matrice.
     */
    private function assertCan(User $actor, Permission $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à effectuer cette opération.');
        }
    }
}
