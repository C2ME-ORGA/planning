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
        $sessionsDeleted = 0;
        $rememberTokensRotated = false;

        try {
            if ($distinctConnection) {
                // Connexion de sessions distincte : ne pas englober l’audit
                // dans la transaction de la connexion par défaut, sinon un
                // échec d’audit annulerait les remember tokens déjà renouvelés.
                $revoked = $sessions->revokeAll();
                $sessionsDeleted = $revoked;
                $rememberTokensRotated = true;

                $audit->record(
                    AuditAction::EmergencyLockdown,
                    null,
                    ['sessions_revoked' => $revoked],
                    'Révocation globale des sessions via la procédure d’urgence.',
                );
            } else {
                // Connexion identique : la révocation et l’audit partagent la
                // même transaction et sont annulés ensemble en cas d’échec.
                $revoked = DB::transaction(function () use (
                    $sessions,
                    $audit,
                    &$sessionsDeleted,
                    &$rememberTokensRotated,
                ): int {
                    $revoked = $sessions->revokeAll();
                    $sessionsDeleted = $revoked;
                    $rememberTokensRotated = true;

                    $audit->record(
                        AuditAction::EmergencyLockdown,
                        null,
                        ['sessions_revoked' => $revoked],
                        'Révocation globale des sessions via la procédure d’urgence.',
                    );

                    return $revoked;
                });
            }
        } catch (PartialSessionRevocationException $exception) {
            $this->error($this->partialMessage($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            if ($distinctConnection && $rememberTokensRotated) {
                $message = $sessionsDeleted > 0
                    ? "Révocation partielle : {$sessionsDeleted} session(s) supprimée(s) sur la connexion dédiée. Les remember tokens ont été renouvelés. L’audit n’a pas été enregistré. Une vérification opérationnelle est requise."
                    : 'Révocation incomplète : aucune session n’a été supprimée. Les remember tokens ont été renouvelés. L’audit n’a pas été enregistré. Une vérification opérationnelle est requise.';

                $this->error($message.' '.$exception->getMessage());
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

        $message = $exception->deletedSessions > 0
            ? "Révocation partielle : {$exception->deletedSessions} session(s) supprimée(s) sur la connexion dédiée, mais le renouvellement des remember tokens a échoué. Aucun audit de réussite n’a été enregistré. Une vérification opérationnelle est requise."
            : 'Opération incomplète : aucune session n’a été supprimée, mais le renouvellement des remember tokens a échoué. Aucun audit de réussite n’a été enregistré. Une vérification opérationnelle est requise.';

        return $cause === null ? $message : $message.' '.$cause;
    }
}
