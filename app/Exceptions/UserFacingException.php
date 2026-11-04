<?php

namespace App\Exceptions;

/**
 * Marque une exception dont le message est rédigé pour l'utilisateur.
 *
 * Seules les exceptions qui implémentent explicitement cette interface peuvent
 * voir leur message présenté dans une interface. Toute autre exception est
 * considérée comme technique par défaut : elle est journalisée et remplacée
 * par un message générique.
 *
 * Ne jamais faire implémenter cette interface par une exception susceptible de
 * contenir un message SQL, un chemin de fichier, un identifiant interne ou une
 * donnée sensible.
 */
interface UserFacingException
{
    public function getMessage(): string;
}
