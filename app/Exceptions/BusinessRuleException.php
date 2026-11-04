<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Refus métier dont le message français est destiné à l'utilisateur.
 *
 * Cette exception reste compatible avec les `catch (RuntimeException)` et les
 * assertions de tests existantes. Elle exprime explicitement qu'un message
 * peut être affiché, contrairement aux exceptions techniques qui doivent être
 * journalisées puis masquées.
 */
class BusinessRuleException extends RuntimeException implements UserFacingException {}
