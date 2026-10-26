<?php

namespace App\Services\Employees;

use App\Models\Team;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Verrou MySQL nommé, ciblé sur une équipe.
 *
 * Il sérialise les opérations qui modifient l'historique des responsables
 * d'une même équipe. Il est toujours acquis avant les verrous salariés afin de
 * conserver un ordre de verrouillage déterministe.
 */
class TeamMutationLock
{
    public function run(Team|int $team, Closure $callback): mixed
    {
        $teamId = $team instanceof Team ? (int) $team->getKey() : $team;
        $name = 'planning.team.'.$teamId;
        $timeout = max(0, (int) config('planning.employees.lock_timeout_seconds', 5));

        $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

        if ($result === null || (int) $result->acquired !== 1) {
            throw new RuntimeException(
                'Une autre opération est en cours sur cette équipe. Réessayez dans quelques instants.',
            );
        }

        try {
            return $callback();
        } finally {
            try {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            } catch (Throwable) {
                // La connexion libère automatiquement ses verrous nommés si
                // elle est perdue ; ne pas masquer l'exception d'origine.
            }
        }
    }
}
