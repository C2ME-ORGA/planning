<?php

namespace App\Enums;

/**
 * Types de mouvement du compteur d'heures.
 *
 * Quatre types sont autorisés : crédit, utilisation, paiement et ajustement.
 */
enum CounterMovementType: string
{
    case Credit = 'credit';
    case Usage = 'usage';
    case Payment = 'payment';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Crédit',
            self::Usage => 'Utilisation',
            self::Payment => 'Paiement',
            self::Adjustment => 'Ajustement',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
