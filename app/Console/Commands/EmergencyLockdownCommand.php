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

        $revoked = $sessions->revokeAll();

        $audit->record(
            AuditAction::EmergencyLockdown,
            null,
            ['sessions_revoked' => $revoked],
            'Révocation globale des sessions via la procédure d’urgence.',
        );

        $this->info("{$revoked} session(s) révoquée(s). Aucun droit supplémentaire n’a été accordé.");

        return self::SUCCESS;
    }
}
