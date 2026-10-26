<?php

namespace App\Services\Access;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Règle centrale d'approbation.
 *
 * Seuls les comptes disposant de `timesheet.validate` peuvent approuver. Un
 * compte ne peut pas approuver sa propre production, sauf Bureau / Direction,
 * et dans ce cas un motif est obligatoire et un événement d'audit dédié est
 * écrit. L'Administrateur technique ne peut jamais approuver.
 *
 * Les lots suivants doivent appeler ce service depuis leurs actions métier ;
 * aucune interface ne doit décider seule de l'approbation.
 */
class ApprovalRules
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function canApprove(User $actor, int $ownerId): bool
    {
        if (! $actor->isActive() || ! $actor->hasPermission(Permission::TimesheetValidate)) {
            return false;
        }

        if ($actor->getKey() === $ownerId) {
            return $actor->role === Role::BureauDirection;
        }

        return true;
    }

    public function requiresReason(User $actor, int $ownerId): bool
    {
        return $actor->getKey() === $ownerId;
    }

    /**
     * @throws RuntimeException
     */
    public function recordSelfApproval(User $actor, int $ownerId, Model $subject, string $reason): AuditEvent
    {
        if ($actor->getKey() !== $ownerId) {
            throw new RuntimeException('Cette opération ne concerne pas une auto-validation.');
        }

        if ($actor->role !== Role::BureauDirection) {
            throw new RuntimeException('L’auto-validation est réservée au Bureau / à la Direction.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new RuntimeException('Un motif d’au moins 10 caractères est requis pour une auto-validation.');
        }

        return $this->audit->record(
            AuditAction::SelfApprovalRecorded,
            $subject,
            ['owner_id' => $ownerId],
            $reason,
            $actor,
        );
    }
}
