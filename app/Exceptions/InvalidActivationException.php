<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Lien d'activation invalide, expiré ou déjà utilisé.
 *
 * Le message est destiné à l'utilisateur et ne contient aucune donnée interne.
 */
class InvalidActivationException extends RuntimeException implements UserFacingException {}
