<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRevoker $sessions,
    ) {}

    public function deactivate(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsDeactivate);
        $this->assertReason($reason);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas se désactiver lui-même.');

        DB::transaction(function () use ($target, $reason, $actor): void {
            $this->lockActiveBureauDirections();
            $target = $this->lockUser($target);

            if (! $target->isActive()) {
                throw new RuntimeException('Ce compte est déjà désactivé.');
            }

            $this->assertNotLastBureauDirection($target);

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

        DB::transaction(function () use ($target, $reason, $actor): void {
            $target = $this->lockUser($target);

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
        });
    }

    public function changeRole(User $target, Role $role, User $actor): void
    {
        $this->assertCan($actor, Permission::RolesAssign);
        $this->assertNotSelf($target, $actor, 'Un compte ne peut pas modifier son propre rôle.');

        DB::transaction(function () use ($target, $role, $actor): void {
            // Verrouille l'ensemble des comptes Bureau/Direction actifs avant
            // la cible, dans un ordre stable, pour éviter qu'une rétrogradation
            // et une désactivation concurrentes ne retirent les deux derniers.
            $this->lockActiveBureauDirections();
            $target = $this->lockUser($target);

            if ($target->role === $role) {
                return;
            }

            if ($target->isBureauDirection() && $role !== Role::BureauDirection) {
                $this->assertNotLastBureauDirection($target);
            }

            $oldRole = $target->role;

            // saveQuietly() évite le doublon avec UserObserver : le service
            // écrit lui-même l'événement avec l'acteur réel de l'opération.
            $target->forceFill(['role' => $role])->saveQuietly();

            $this->audit->record(
                AuditAction::RoleChanged,
                $target,
                [
                    'role' => [
                        'old' => $oldRole->value,
                        'new' => $role->value,
                    ],
                ],
                null,
                $actor,
            );

            $this->sessions->revoke($target);
        });
    }

    public function resetMultiFactor(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        DB::transaction(function () use ($target, $reason, $actor): void {
            $target = $this->lockUser($target);

            // saveQuietly() évite l'événement MfaDisabled de UserObserver :
            // l'événement MfaReset ci-dessous porte l'acteur et le motif.
            $target->forceFill([
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
            ])->saveQuietly();

            $this->sessions->revoke($target);

            $this->audit->record(AuditAction::MfaReset, $target, [], $reason, $actor);
        });
    }

    public function revokeSessions(User $target, string $reason, User $actor): void
    {
        $this->assertCan($actor, Permission::AccountsUpdate);
        $this->assertReason($reason);

        DB::transaction(function () use ($target, $reason, $actor): void {
            $target = $this->lockUser($target);

            $revoked = $this->sessions->revoke($target);

            $this->audit->record(
                AuditAction::SessionsRevoked,
                $target,
                ['sessions_revoked' => $revoked],
                $reason,
                $actor,
            );
        });
    }

    /**
     * Recharge et verrouille la ligne du compte dans la transaction courante.
     */
    private function lockUser(User $target): User
    {
        $locked = User::query()
            ->whereKey($target->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof User) {
            throw new RuntimeException('Ce compte est introuvable.');
        }

        return $locked;
    }

    /**
     * Verrouille tous les comptes Bureau/Direction actifs dans un ordre stable.
     *
     * Ce verrou d'ensemble empêche deux transactions de retirer simultanément
     * les deux derniers comptes Bureau/Direction actifs.
     */
    private function lockActiveBureauDirections(): void
    {
        User::query()
            ->active()
            ->bureauDirection()
            ->select('id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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
