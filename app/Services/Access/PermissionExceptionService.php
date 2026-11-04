<?php

namespace App\Services\Access;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\PermissionException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exceptions de permission ciblées.
 *
 * Une exception :
 * - porte sur une capacité métier bornée (jamais un rôle, un compte ou l'audit) ;
 * - est justifiée, datée, révocable et d'une durée maximale de douze mois ;
 * - ne peut pas être accordée à soi-même ni à un Administrateur technique ;
 * - est journalisée à la création et à la révocation.
 */
class PermissionExceptionService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function grant(
        User $target,
        Permission $permission,
        string $reason,
        CarbonInterface $expiresAt,
        User $actor,
    ): PermissionException {
        $this->assertCanManage($actor);
        $this->assertReason($reason);

        if ($target->is($actor)) {
            throw new BusinessRuleException('Une exception ne peut pas être accordée à soi-même.');
        }

        if (! $target->isActive()) {
            throw new BusinessRuleException('Une exception ne peut pas être accordée à un compte inactif.');
        }

        if ($target->isAdministrator()) {
            throw new BusinessRuleException('Un Administrateur technique ne reçoit pas de capacité métier par exception.');
        }

        if (! $permission->isExceptionEligible()) {
            throw new BusinessRuleException('Cette capacité n’est pas éligible à une exception.');
        }

        if ($target->hasPermission($permission)) {
            throw new BusinessRuleException('Ce compte dispose déjà de cette capacité.');
        }

        $maxExpiry = now()->addMonths((int) config('planning.exceptions.max_duration_months', 12));

        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw new BusinessRuleException('La date d’expiration doit être dans le futur.');
        }

        if ($expiresAt->greaterThan($maxExpiry)) {
            throw new BusinessRuleException('La durée maximale d’une exception est de douze mois.');
        }

        return DB::transaction(function () use ($target, $permission, $reason, $expiresAt, $actor): PermissionException {
            // Le verrou sur le compte cible sérialise toutes les créations
            // d'exception pour ce compte. La vérification de doublon est
            // refaite après acquisition du verrou.
            $lockedTarget = User::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedTarget instanceof User) {
                throw new BusinessRuleException('Ce compte est introuvable.');
            }

            if (! $lockedTarget->isActive()) {
                throw new BusinessRuleException('Une exception ne peut pas être accordée à un compte inactif.');
            }

            if ($lockedTarget->isAdministrator()) {
                throw new BusinessRuleException('Un Administrateur technique ne reçoit pas de capacité métier par exception.');
            }

            if ($lockedTarget->hasPermission($permission)) {
                throw new BusinessRuleException('Ce compte dispose déjà de cette capacité.');
            }

            $alreadyGranted = PermissionException::query()
                ->where('user_id', $lockedTarget->getKey())
                ->where('permission', $permission->value)
                ->active()
                ->exists();

            if ($alreadyGranted) {
                throw new BusinessRuleException('Une exception active identique existe déjà.');
            }

            $exception = PermissionException::query()->create([
                'user_id' => $lockedTarget->getKey(),
                'permission' => $permission->value,
                'reason' => $reason,
                'granted_by_id' => $actor->getKey(),
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->audit->record(
                AuditAction::PermissionExceptionGranted,
                $lockedTarget,
                [
                    'exception_id' => $exception->getKey(),
                    'permission' => $permission->value,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                $reason,
                $actor,
            );

            return $exception;
        });
    }

    public function revoke(PermissionException $exception, string $reason, User $actor): void
    {
        $this->assertCanManage($actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($exception, $reason, $actor): void {
            $locked = PermissionException::query()
                ->whereKey($exception->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof PermissionException) {
                throw new BusinessRuleException('Cette exception est introuvable.');
            }

            if ($locked->revoked_at !== null) {
                throw new BusinessRuleException('Cette exception est déjà révoquée.');
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_id' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->record(
                AuditAction::PermissionExceptionRevoked,
                $locked->user,
                [
                    'exception_id' => $locked->getKey(),
                    'permission' => $locked->permission->value,
                ],
                $reason,
                $actor,
            );
        });
    }

    /**
     * @return Collection<int, PermissionException>
     */
    public function forReview(): Collection
    {
        return PermissionException::query()
            ->active()
            ->orderBy('expires_at')
            ->get();
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::PermissionExceptionsManage)) {
            throw new BusinessRuleException('Ce compte ne peut pas gérer les exceptions de permission.');
        }
    }

    private function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));

        if ($length < 10) {
            throw new BusinessRuleException('Un motif d’au moins 10 caractères est requis.');
        }

        if ($length > 500) {
            throw new BusinessRuleException('Le motif ne peut pas dépasser 500 caractères.');
        }
    }
}
