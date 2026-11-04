<?php

namespace App\Enums;

/**
 * Décision de revue d’un chevauchement de segments.
 *
 * Une décision est toujours motivée et rattachée au cycle de correction
 * courant. Seule une décision acceptée autorise la validation finale.
 */
enum OverlapReviewDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Chevauchement accepté',
            self::Rejected => 'Chevauchement rejeté',
        };
    }

    public function blocksValidation(): bool
    {
        return $this === self::Rejected;
    }
}
