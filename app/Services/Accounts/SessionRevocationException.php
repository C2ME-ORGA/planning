<?php

namespace App\Services\Accounts;

use RuntimeException;
use Throwable;

/**
 * Échec de l'invalidation logique d'une session ou d'une génération.
 *
 * Cette exception est distincte d'un échec de nettoyage physique : elle
 * signifie que la révocation logique n'a pas été acquise et qu'aucun audit de
 * réussite ne doit être écrit.
 */
class SessionRevocationException extends RuntimeException
{
    public function __construct(
        public readonly string $scope,
        public readonly string $step,
        Throwable $previous,
    ) {
        parent::__construct(
            "La révocation logique des sessions a échoué ({$scope}, {$step}).",
            0,
            $previous,
        );
    }
}
