<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sortie refusée tant que des affectations ou responsabilités resteraient
 * actives au-delà de la date de sortie. Chaque blocage est présenté à
 * l'utilisateur pour correction manuelle explicite.
 */
class EmployeeExitBlockedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(
            'La sortie est bloquée : corrigez ou clôturez explicitement les éléments suivants.',
        );
    }
}
