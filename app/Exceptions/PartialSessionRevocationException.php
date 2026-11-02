<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Signale que les sessions ont été supprimées sur une connexion dédiée mais
 * qu'une étape ultérieure de la révocation a échoué.
 *
 * Aucune compensation automatique n'est tentée : l'exploitant doit vérifier
 * l'état réel des sessions, des remember tokens et de l'audit.
 */
class PartialSessionRevocationException extends RuntimeException
{
    public function __construct(
        public readonly int $deletedSessions,
        public readonly bool $rememberTokensRotated,
        public readonly bool $auditRecorded,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'La révocation des sessions est partielle : une vérification opérationnelle est requise.',
            0,
            $previous,
        );
    }
}
