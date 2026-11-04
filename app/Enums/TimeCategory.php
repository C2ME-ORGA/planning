<?php

namespace App\Enums;

/**
 * Catégories autorisées d'un segment de relevé d'heures.
 *
 * Aucune catégorie libre n'est acceptée : la colonne est contrôlée par un
 * `CHECK` SQL en plus du cast Eloquent.
 */
enum TimeCategory: string
{
    case TravailEffectif = 'travail_effectif';
    case Trajet = 'trajet';
    case Pause = 'pause';
    case Formation = 'formation';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::TravailEffectif => 'Travail effectif',
            self::Trajet => 'Trajet',
            self::Pause => 'Pause',
            self::Formation => 'Formation',
            self::Autre => 'Autre',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            $options[$category->value] = $category->label();
        }

        return $options;
    }
}
