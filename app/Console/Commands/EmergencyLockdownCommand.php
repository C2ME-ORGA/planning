<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Services\Accounts\SessionRevoker;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Procédure d'urgence : coupe toutes les sessions existantes.
 *
 * Cette commande ne crée, ne restaure et ne contourne aucun droit métier.
 * Aucun mécanisme de "break-glass" accordant un accès n'existe dans le MVP.
 */
class EmergencyLockdownCommand extends Command
{
    protected $signature = 'planning:emergency-lockdown {--confirm : Confirme la révocation de toutes les sessions}';

    protected $description = 'Révoque immédiatement toutes les sessions et jetons persistants, sans accorder de droit.';

    public function handle(SessionRevoker $sessions, AuditLogger $audit): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour révoquer toutes les sessions.');

            return self::FAILURE;
        }

        $result = $sessions->revokeAll();

        $audit->record(
            AuditAction::EmergencyLockdown,
            null,
            [
                'global_generation' => $result->generation,
                'sessions_revoked' => $result->deletedSessions,
                'session_storage' => $result->sessionDriver,
                'physical_cleanup_required' => $result->physicalCleanupRequired,
                'physical_cleanup_error' => $result->physicalError,
                'fully_completed' => $result->isPhysicalCleanupComplete(),
            ],
            'Révocation globale des sessions via la procédure d’urgence.',
        );

        $this->info(
            "Génération globale {$result->generation} active : toutes les anciennes sessions et cookies persistants sont invalides.",
        );

        if (! $result->isPhysicalCleanupComplete()) {
            $this->warn(
                'La révocation logique est effective, mais le nettoyage physique des sessions a échoué. '
                .'Les anciennes sessions restent inutilisables ; vérifiez le stockage de sessions.',
            );

            return self::FAILURE;
        }

        $this->info("{$result->deletedSessions} session(s) supprimée(s) du stockage physique.");

        return self::SUCCESS;
    }
}
