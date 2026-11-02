<?php

namespace App\Services\Accounts;

use App\Exceptions\PartialSessionRevocationException;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Révocation immédiate des sessions et jetons persistants d'un compte.
 *
 * Cette implémentation ne prend en charge que le pilote SQL `database`.
 * Tout autre pilote (`redis`, `file`, etc.) est refusé explicitement avant
 * toute suppression ou modification de remember token.
 */
class SessionRevoker
{
    public function revoke(User $user): int
    {
        $this->assertSupportedDriver();
        $connection = $this->sessionConnection();
        $this->assertSupportedSessionStore($connection);

        $table = (string) config('session.table', 'sessions');

        $deleteSessions = fn (): int => $connection->table($table)
            ->where('user_id', $user->getKey())
            ->delete();

        if ($this->usesDefaultConnection($connection)) {
            return $connection->transaction(function () use ($deleteSessions, $user): int {
                $deleted = $deleteSessions();
                $this->rotateRememberToken($user);

                return $deleted;
            });
        }

        // Connexions distinctes : une transaction SQL unique ne peut pas couvrir
        // deux connexions. La suppression des sessions est transactionnelle sur
        // sa connexion et précède le renouvellement du jeton sur la connexion
        // par défaut.
        $deleted = $connection->transaction($deleteSessions);
        DB::transaction(fn () => $this->rotateRememberToken($user));

        return $deleted;
    }

    public function revokeAll(): int
    {
        $this->assertSupportedDriver();
        $connection = $this->sessionConnection();
        $this->assertSupportedSessionStore($connection);

        $table = (string) config('session.table', 'sessions');
        $deleteSessions = fn (): int => $connection->table($table)->delete();

        if ($this->usesDefaultConnection($connection)) {
            return $connection->transaction(function () use ($deleteSessions): int {
                $deleted = $deleteSessions();
                $this->rotateAllRememberTokens();

                return $deleted;
            });
        }

        // Même limite que revoke() : deux connexions ne partagent pas une
        // transaction SQL. Les sessions sont supprimées d'abord.
        $deleted = $connection->transaction($deleteSessions);

        try {
            DB::transaction(fn () => $this->rotateAllRememberTokens());
        } catch (Throwable $exception) {
            throw new PartialSessionRevocationException(
                sessionsDeleted: $deleted > 0,
                rememberTokensRotated: false,
                auditRecorded: false,
                previous: $exception,
            );
        }

        return $deleted;
    }

    /**
     * Indique si les sessions utilisent une connexion distincte de la
     * connexion par défaut.
     */
    public function usesDistinctConnection(): bool
    {
        $configured = config('session.connection');

        if (! is_string($configured) || trim($configured) === '') {
            return false;
        }

        return trim($configured) !== DB::getDefaultConnection();
    }

    /**
     * Résout la connexion réellement utilisée par le stockage des sessions.
     *
     * @throws RuntimeException
     */
    private function sessionConnection(): Connection
    {
        $configured = config('session.connection');
        $name = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;

        try {
            return DB::connection($name);
        } catch (Throwable $exception) {
            $label = $name ?? 'par défaut';

            throw new RuntimeException(
                "La connexion de sessions « {$label} » est indisponible ou inconnue.",
                0,
                $exception,
            );
        }
    }

    /**
     * Refuse tout pilote non SQL avant toute connexion ou écriture.
     *
     * @throws RuntimeException
     */
    private function assertSupportedDriver(): void
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            throw new RuntimeException(
                "La révocation SQL des sessions exige SESSION_DRIVER=database ; pilote configuré : {$driver}."
            );
        }
    }

    /**
     * Vérifie la table et ses colonnes sur la connexion effective.
     *
     * @throws RuntimeException
     */
    private function assertSupportedSessionStore(Connection $connection): void
    {
        $table = (string) config('session.table', 'sessions');
        $connectionName = $connection->getName() ?? 'par défaut';

        try {
            $schema = $connection->getSchemaBuilder();
            $valid = $schema->hasTable($table)
                && $schema->hasColumns($table, ['id', 'user_id']);
        } catch (Throwable $exception) {
            // Erreur réelle de connexion ou d'inspection du schéma : elle
            // survient souvent au premier accès PDO, pas dans DB::connection().
            throw new RuntimeException(
                "Impossible de vérifier la table de sessions « {$table} » sur la connexion « {$connectionName} ».",
                0,
                $exception,
            );
        }

        if (! $valid) {
            throw new RuntimeException(
                "La table de sessions « {$table} » est absente ou incomplète sur la connexion « {$connectionName} » (colonnes id, user_id requises)."
            );
        }
    }

    private function usesDefaultConnection(Connection $connection): bool
    {
        return $connection->getName() === DB::getDefaultConnection();
    }

    private function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    /**
     * Parcours borné : les utilisateurs sont traités par lots de 500.
     */
    private function rotateAllRememberTokens(): void
    {
        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, static function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'remember_token' => Str::random(60),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
}
