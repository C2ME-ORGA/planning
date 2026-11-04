<?php

namespace App\Enums;

/**
 * Direction d'un ajustement de compteur.
 *
 * Uniquement utilisée pour les mouvements de type `adjustment` :
 * `credit` augmente le solde, `debit` le diminue.
 */
enum CounterAdjustmentDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Crédit',
            self::Debit => 'Débit',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $direction) {
            $options[$direction->value] = $direction->label();
        }

        return $options;
    }
}
