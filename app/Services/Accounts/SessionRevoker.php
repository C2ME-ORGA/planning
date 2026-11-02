<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation immédiate des sessions et jetons persistants d'un compte.
 *
 * Les lignes de session sont supprimées sur la connexion et dans la table
 * réellement configurées (`session.connection`, `session.table`). Ce nettoyage
 * SQL n'est toutefois décisif que pour le pilote `database`.
 *
 * Pour les autres pilotes (`file`, `redis`, etc.), Laravel ne permet pas de
 * retrouver de façon fiable toutes les sessions d'un utilisateur. Le compteur
 * `users.session_version` sert alors de garde-fou indépendant du stockage :
 * il est incrémenté à chaque révocation, et `EnsureAccountIsActive` refuse
 * toute session dont la version enregistrée à la connexion ne correspond plus.
 * La révocation reste donc effective après une réactivation, même si
 * d'anciennes sessions subsistent dans le stockage du pilote.
 */
class SessionRevoker
{
    public function revoke(User $user): int
    {
        $deleted = $this->sessionTable()->where('user_id', $user->getKey())->delete();

        $this->rotateCredentials($user);

        return $deleted;
    }

    public function revokeAll(): int
    {
        $deleted = $this->sessionTable()->delete();

        // Chunking par identifiant : aucun chargement complet de la table en
        // mémoire, y compris sur un volume important de comptes.
        User::query()
            ->select(['id'])
            ->lazyById(500)
            ->each(function (User $user): void {
                $this->rotateCredentials($user);
            });

        return $deleted;
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

    /**
     * Renouvelle le jeton "remember me" et invalide toutes les sessions
     * existantes via le compteur de version.
     */
    private function rotateCredentials(User $user): void
    {
        User::query()
            ->whereKey($user->getKey())
            ->update([
                'remember_token' => Str::random(60),
                'session_version' => DB::raw('session_version + 1'),
            ]);
    }
}
