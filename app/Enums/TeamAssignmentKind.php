<?php

namespace App\Enums;

/**
 * Nature d'une affectation d'équipe.
 *
 * Une affectation temporaire est un enregistrement distinct : elle ne modifie
 * jamais l'équipe principale historisée du salarié.
 */
enum TeamAssignmentKind: string
{
    case Primary = 'primary';
    case Temporary = 'temporary';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Équipe principale',
            self::Temporary => 'Affectation temporaire',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $kind) {
            $options[$kind->value] = $kind->label();
        }

        return $options;
    }
}
