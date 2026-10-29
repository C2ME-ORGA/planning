<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Normalisation des motifs de workflow des relevés d'heures.
 *
 * Contrat unique, partagé par l'autorisation et par l'écriture d'audit :
 * - un motif facultatif absent reste `null` ;
 * - un motif facultatif vide après `trim()` devient `null` ;
 * - un motif obligatoire absent, vide ou blanc est refusé ;
 * - les espaces périphériques sont supprimés ;
 * - la longueur est bornée à 500 caractères Unicode ;
 * - aucune valeur par défaut n'est inventée et aucune autre transformation
 *   n'est appliquée.
 *
 * La valeur retournée doit être utilisée telle quelle pour l'autorisation Gate
 * et pour `audit_events.reason`, afin que la valeur validée et la valeur
 * enregistrée soient identiques.
 */
final class TimesheetReason
{
    public const MAX_LENGTH = 500;

    public static function optional(?string $reason, string $field = 'reason'): ?string
    {
        if ($reason === null) {
            return null;
        }

        $normalized = trim($reason);

        if ($normalized === '') {
            return null;
        }

        self::assertLength($normalized, $field);

        return $normalized;
    }

    public static function required(?string $reason, string $field = 'reason'): string
    {
        $normalized = self::optional($reason, $field);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'Un motif est obligatoire pour cette opération.',
            ]);
        }

        return $normalized;
    }

    private static function assertLength(string $reason, string $field): void
    {
        if (mb_strlen($reason) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                $field => sprintf('Le motif ne peut pas dépasser %d caractères.', self::MAX_LENGTH),
            ]);
        }
    }
}
