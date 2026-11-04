<?php

namespace App\Enums;

/**
 * Demi-journée métier d'une affectation de planning.
 *
 * La date civile est stockée séparément (`work_date`) : la demi-journée ne porte
 * aucune heure et ne dépend donc pas du fuseau horaire.
 */
enum PlanningHalfDay: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Matin',
            self::Afternoon => 'Après-midi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Morning => 'info',
            self::Afternoon => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $halfDay) {
            $options[$halfDay->value] = $halfDay->label();
        }

        return $options;
    }
}
