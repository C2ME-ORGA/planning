<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Révocation immédiate des sessions et jetons persistants.
 *
 * Ordre volontaire pour chaque portée :
 * 1. invalidation logique (version utilisateur ou génération globale) ;
 * 2. rotation des identifiants persistants ;
 * 3. nettoyage physique des sessions si le stockage le permet.
 *
 * Une panne du nettoyage physique ne peut donc pas rendre une ancienne
 * session valide. Une panne de l'invalidation logique est propagée : aucun
 * audit de réussite ne doit être écrit dans ce cas.
 */
class SessionRevoker
{
    public function __construct(
        private readonly AuthenticationGeneration $generation,
    ) {}

    public function revoke(User $user): SessionRevocationResult
    {
        try {
            // L'UPDATE et la relecture forment une seule unité logique : si la
            // lecture échoue après l'UPDATE, la transaction annule les deux.
            // Depuis une transaction métier existante, Laravel utilise un
            // savepoint et l'appelant conserve la maîtrise du rollback global.
            $version = DB::transaction(function () use ($user): int {
                $updated = User::query()
                    ->whereKey($user->getKey())
                    ->update([
                        'remember_token' => Str::random(60),
                        'session_version' => DB::raw('session_version + 1'),
                    ]);

                if ($updated !== 1) {
                    throw new RuntimeException('Le compte à révoquer est introuvable.');
                }

                $version = User::query()->whereKey($user->getKey())->value('session_version');

                if ($version === null) {
                    throw new RuntimeException('La version de session du compte est introuvable.');
                }

                return (int) $version;
            });
        } catch (Throwable $exception) {
            throw new SessionRevocationException('user', 'logical', $exception);
        }

        return $this->buildResult('user', $version, $this->purgeSessions($user->getKey()));
    }

    public function revokeAll(): SessionRevocationResult
    {
        try {
            // Étape critique : génération globale et invalidation de tous les
            // cookies remember-me dans une seule transaction. Une interruption
            // après le commit laisse déjà toutes les anciennes authentifications
            // inutilisables.
            $generation = $this->generation->revokeAll();
        } catch (Throwable $exception) {
            throw new SessionRevocationException('global', 'logical', $exception);
        }

        return $this->buildResult('global', $generation, $this->purgeSessions(null));
    }

    /**
     * Nettoie les sessions physiques uniquement pour le pilote SQL.
     *
     * Les pilotes `redis`, `file`, `cookie`, `array`, etc. ne partagent pas la
     * table `sessions` : interroger cette table produirait une fausse erreur
     * (ou supprimerait des lignes sans rapport). Le nettoyage est alors
     * explicitement non applicable, sans jamais remettre en cause la
     * révocation logique déjà acquise.
     *
     * @return array{deleted: int, driver: string, required: bool, error: ?string}
     */
    private function purgeSessions(?int $userId): array
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            return [
                'deleted' => 0,
                'driver' => $driver,
                'required' => false,
                'error' => null,
            ];
        }

        try {
            $query = $this->sessionTable();

            if ($userId !== null) {
                $query->where('user_id', $userId);
            }

            return [
                'deleted' => $query->delete(),
                'driver' => $driver,
                'required' => true,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('Nettoyage physique des sessions échoué.', [
                'driver' => $driver,
                'scope' => $userId === null ? 'global' : 'user',
                'user_id' => $userId,
                'error_class' => $exception::class,
            ]);

            return [
                'deleted' => 0,
                'driver' => $driver,
                'required' => true,
                'error' => $exception::class,
            ];
        }
    }

    /**
     * @param  array{deleted: int, driver: string, required: bool, error: ?string}  $physical
     */
    private function buildResult(string $scope, int $generation, array $physical): SessionRevocationResult
    {
        return new SessionRevocationResult(
            scope: $scope,
            generation: $generation,
            deletedSessions: $physical['deleted'],
            sessionDriver: $physical['driver'],
            physicalCleanupRequired: $physical['required'],
            physicalError: $physical['error'],
        );
    }

    /**
     * Cible la connexion réellement utilisée pour les sessions.
     *
     * `DB::connection(null)` retourne la connexion par défaut : c'est le
     * comportement attendu lorsque `session.connection` n'est pas renseignée.
     */
    private function sessionTable(): Builder
    {
        $connection = config('session.connection');
        $table = (string) config('session.table', 'sessions');

        return DB::connection($connection)->table($table);
    }
}
