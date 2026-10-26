<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\TeamLeadershipService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRevoker $sessions,
        private readonly TeamLeadershipService $leaderships,
    ) {}

    public function deactivate(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsDeactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se désactiver lui-même.');

        if (! $target->isActive()) {
            throw new RuntimeException('Ce compte est déjà désactivé.');
        }

        $this->assertNotLastBureauDirection($target);
        $this->leaderships->assertDeactivationAllowed($target);

        DB::transaction(function () use ($target, $reason, $actor): void {
            $target->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
            ])->save();

            $revoked = $this->sessions->revoke($target);

            $this->audit->record(
                AuditAction::AccountDeactivated,
                $target,
                [
                    'is_active' => ['old' => true, 'new' => false],
                    'sessions_revoked' => $revoked,
                ],
                $reason,
                $actor,
            );
        });
    }

    public function reactivate(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsReactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se réactiver lui-même.');

        if ($target->isActive()) {
            throw new RuntimeException('Ce compte est déjà actif.');
        }

        $target->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ])->save();

        $this->audit->record(
            AuditAction::AccountReactivated,
            $target,
            ['is_active' => ['old' => false, 'new' => true]],
            $reason,
            $actor,
        );

        $this->sessions->revoke($target);
    }

    public function changeRole(User $target, Role $role, User $actor): void
    {
        $this->assertCan($actor, Permission::RolesAssign);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas modifier son propre rôle.');

        if ($target->role === $role) {
            return;
        }

        if ($target->isBureauDirection() && $role !== Role::BureauDirection) {
            $this->assertNotLastBureauDirection($target);
        }

        // Une responsabilité d'équipe non terminée exige un rôle compatible.
        $this->leaderships->assertRoleRemovalAllowed($target, $role);

        $target->forceFill(['role' => $role])->save();

        // Le journal est écrit par UserObserver (acteur = utilisateur courant).
        $this->sessions->revoke($target);
    }

    public function resetMultiFactor(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        $target->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        $this->sessions->revoke($target);

        $this->audit->record(AuditAction::MfaReset, $target, [], $reason, $actor);
    }

    public function revokeSessions(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        $revoked = $this->sessions->revoke($target);

        $this->audit->record(
            AuditAction::SessionsRevoked,
            $target,
            ['sessions_revoked' => $revoked],
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
