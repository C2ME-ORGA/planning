<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Exceptions\PartialSessionRevocationException;
use App\Services\Accounts\SessionRevoker;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        try {
            $audit->assertConfigured();
        } catch (Throwable $exception) {
            $this->error('Configuration d’audit invalide : '.$exception->getMessage());

            return self::FAILURE;
        }

        $distinctConnection = $sessions->usesDistinctConnection();
        $sessionsRevoked = false;

        try {
            // La transaction couvre la révocation en base et l’audit sur la
            // connexion principale. Avec une connexion de sessions distincte,
            // la suppression des sessions est déjà validée par sa propre
            // transaction avant l’audit.
            $revoked = DB::transaction(function () use ($sessions, $audit, &$sessionsRevoked): int {
                $revoked = $sessions->revokeAll();
                $sessionsRevoked = true;

                $audit->record(
                    AuditAction::EmergencyLockdown,
                    null,
                    ['sessions_revoked' => $revoked],
                    'Révocation globale des sessions via la procédure d’urgence.',
                );

                return $revoked;
            });
        } catch (PartialSessionRevocationException $exception) {
            $this->error($this->partialMessage($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            if ($sessionsRevoked && $distinctConnection) {
                $this->error(
                    'Révocation partielle : les sessions ont été supprimées, mais le renouvellement des '
                    .'remember tokens et l’audit n’ont pas été validés. Une vérification opérationnelle est requise. '
                    .$exception->getMessage()
                );
            } elseif ($distinctConnection) {
                $this->error(
                    'Opération non exécutée : aucune session n’a été supprimée. '
                    .$exception->getMessage()
                );
            } else {
                $this->error(
                    'Révocation annulée : aucune session n’a été supprimée. '
                    .$exception->getMessage()
                );
            }

            return self::FAILURE;
        }

        $this->info("{$revoked} session(s) révoquée(s). Aucun droit supplémentaire n’a été accordé.");

        return self::SUCCESS;
    }

    private function partialMessage(PartialSessionRevocationException $exception): string
    {
        $cause = $exception->getPrevious()?->getMessage();

        $message = $exception->sessionsDeleted
            ? 'Révocation partielle : les sessions ont été supprimées, mais le renouvellement des remember tokens a échoué. Aucun audit de réussite n’a été enregistré. Une vérification opérationnelle est requise.'
            : 'Opération non exécutée : aucune session n’a été supprimée, mais une étape ultérieure a échoué.';

        return $cause === null ? $message : $message.' '.$cause;
    }
}
