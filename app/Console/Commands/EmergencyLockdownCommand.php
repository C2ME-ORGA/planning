<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCliActor;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use App\Services\Accounts\SessionRevocationException;
use App\Services\Accounts\SessionRevoker;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Procédure d'urgence : coupe toutes les sessions existantes.
 *
 * Cette commande ne crée, ne restaure et ne contourne aucun droit métier.
 * Aucun mécanisme de "break-glass" accordant un accès n'existe dans le MVP.
 * Elle exige un compte habilité et un motif, et refuse de démarrer si
 * l'événement d'intention ne peut pas être audité.
 */
class EmergencyLockdownCommand extends Command
{
    use ResolvesCliActor;

    protected $signature = 'planning:emergency-lockdown
        {--actor= : Compte habilité qui déclenche le verrouillage}
        {--reason= : Justification obligatoire}
        {--confirm : Confirme la révocation de toutes les sessions}';

    protected $description = 'Révoque immédiatement toutes les sessions et jetons persistants, sans accorder de droit.';

    public function handle(
        SessionRevoker $sessions,
        AuditLogger $audit,
        AccountAdministration $accounts,
    ): int {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour révoquer toutes les sessions.');

            return self::FAILURE;
        }

        $actor = $this->resolveActorWith(Permission::AccountsUpdate);

        if ($actor === null) {
            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        try {
            $accounts->assertReason($reason);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $audit->record(
                AuditAction::EmergencyLockdownRequested,
                null,
                ['scope' => 'global'],
                $reason,
                $actor,
            );
        } catch (Throwable $exception) {
            $this->error(
                'Le verrouillage est refusé : l’événement d’intention ne peut pas être audité. '
                .'Aucune session n’a été révoquée.',
            );
            report($exception);

            return self::FAILURE;
        }

        try {
            $result = $sessions->revokeAll();
        } catch (SessionRevocationException $exception) {
            $this->recordFailure($audit, $exception, $reason, $actor);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $audit->record(
                AuditAction::EmergencyLockdown,
                null,
                [
                    'global_generation' => $result->generation,
                    'sessions_revoked' => $result->deletedSessions,
                    'session_storage' => $result->sessionDriver,
                    'physical_cleanup_required' => $result->physicalCleanupRequired,
                    'physical_cleanup_state' => $result->physicalCleanupState()->value,
                    'physical_cleanup_error' => $result->physicalError,
                    'fully_completed' => ! $result->hasPhysicalCleanupFailure(),
                ],
                $reason,
                $actor,
            );
        } catch (Throwable $exception) {
            $this->error(
                'La révocation logique est effective, mais l’audit final est indisponible. '
                .'Vérifiez la génération globale et le journal d’audit.',
            );
            report($exception);

            return self::FAILURE;
        }

        $this->info(
            "Génération globale {$result->generation} active : toutes les anciennes sessions et cookies persistants sont invalides.",
        );

        if ($result->hasPhysicalCleanupFailure()) {
            $this->warn(
                'La révocation logique est effective, mais le nettoyage physique des sessions a échoué. '
                .'Les anciennes sessions restent inutilisables ; vérifiez le stockage de sessions.',
            );

            return self::FAILURE;
        }

        $this->info("{$result->deletedSessions} session(s) supprimée(s) du stockage physique.");

        return self::SUCCESS;
    }

    private function recordFailure(
        AuditLogger $audit,
        SessionRevocationException $exception,
        string $reason,
        User $actor,
    ): void {
        try {
            $audit->record(
                AuditAction::SessionRevocationFailed,
                null,
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
            report($auditFailure);
        }
    }
}
