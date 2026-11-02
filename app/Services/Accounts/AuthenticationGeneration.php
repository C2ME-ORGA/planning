<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Génération globale d'authentification.
 *
 * Une seule ligne (id = 1) porte la génération courante. Son incrément et la
 * suppression de tous les jetons remember-me sont réalisés dans une même
 * transaction sur la connexion des utilisateurs. Dès le commit :
 *
 * - toutes les sessions existantes portent une ancienne génération ;
 * - tous les cookies remember-me existants ne correspondent plus à un jeton.
 *
 * Le nettoyage physique des sessions est volontairement séparé : il ne
 * conditionne jamais la sécurité de la révocation.
 */
class AuthenticationGeneration
{
    public const TABLE = 'authentication_state';

    private const SINGLETON_ID = 1;

    public function current(): int
    {
        // Lecture forcée sur la connexion d'écriture : une réplique en retard
        // ne doit jamais servir une ancienne génération.
        $generation = DB::table(self::TABLE)
            ->useWritePdo()
            ->where('id', self::SINGLETON_ID)
            ->value('global_generation');

        if ($generation === null || (int) $generation < 1) {
            throw new RuntimeException('La génération globale d’authentification est invalide ou introuvable.');
        }

        return (int) $generation;
    }

    /**
     * Active immédiatement une nouvelle génération globale.
     *
     * Aucun utilisateur n'est chargé en mémoire : les jetons persistants sont
     * invalidés par un seul UPDATE.
     */
    public function revokeAll(): int
    {
        return DB::transaction(function (): int {
            // Refuse une valeur corrompue avant l'incrément : revenir à une
            // génération antérieure ne doit jamais être possible par accident.
            $this->current();

            $updated = DB::table(self::TABLE)
                ->where('id', self::SINGLETON_ID)
                ->update([
                    'global_generation' => DB::raw('global_generation + 1'),
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('La génération globale d’authentification est introuvable.');
            }

            // Invalide tous les recallers sans parcourir les comptes.
            User::query()->update(['remember_token' => null]);

            return $this->current();
        });
    }
}
