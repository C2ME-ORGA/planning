<?php

namespace App\Services\Hours;

use App\Exceptions\BusinessRuleException;

/**
 * Référence mensuelle proratisée selon les jours calendaires de présence.
 *
 * Le calcul reste en minutes. L'arrondi est appliqué une seule fois, sur le
 * résultat final, au quart d'heure le plus proche : un reste de 0 à 7 minutes
 * arrondit vers le bas, un reste de 8 à 14 minutes vers le haut. Une référence
 * couvrant le mois entier n'est pas arrondie.
 */
final class ProratedReference
{
    public const QUARTER_HOUR_MINUTES = 15;

    /**
     * @return array{
     *     reference_minutes: int,
     *     exact_numerator: int,
     *     exact_denominator: int,
     *     is_prorated: bool
     * }
     */
    public static function calculate(
        int $referenceMinutes,
        int $presenceDays,
        int $daysInMonth,
    ): array {
        if ($referenceMinutes <= 0) {
            throw new BusinessRuleException('La référence mensuelle doit être strictement positive.');
        }

        if ($daysInMonth <= 0) {
            throw new BusinessRuleException('Le nombre de jours du mois doit être strictement positif.');
        }

        if ($presenceDays < 0 || $presenceDays > $daysInMonth) {
            throw new BusinessRuleException('Le nombre de jours de présence est incohérent avec le mois.');
        }

        if ($presenceDays === 0) {
            return [
                'reference_minutes' => 0,
                'exact_numerator' => 0,
                'exact_denominator' => 1,
                'is_prorated' => true,
            ];
        }

        if ($presenceDays === $daysInMonth) {
            return [
                'reference_minutes' => $referenceMinutes,
                'exact_numerator' => $referenceMinutes,
                'exact_denominator' => 1,
                'is_prorated' => false,
            ];
        }

        $numerator = $referenceMinutes * $presenceDays;
        $denominator = $daysInMonth;

        return [
            'reference_minutes' => self::roundToQuarterHour($numerator, $denominator),
            'exact_numerator' => $numerator,
            'exact_denominator' => $denominator,
            'is_prorated' => true,
        ];
    }

    /**
     * Arrondit une valeur exprimée en minutes, sous forme de fraction, au quart
     * d'heure le plus proche.
     *
     * Règle validée :
     *  - de 0 min 00 s à 7 min 00 s inclus : arrondi inférieur ;
     *  - au-delà de 7 min 00 s : arrondi supérieur.
     *
     * Le calcul évite toute conversion intermédiaire qui ferait perdre la
     * précision. Le reste est comparé directement à 7 minutes exprimées dans
     * la même unité que le numérateur.
     */
    public static function roundToQuarterHour(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new BusinessRuleException('La valeur à arrondir doit être positive.');
        }

        $quarterSize = self::QUARTER_HOUR_MINUTES;
        $unitsPerQuarter = $quarterSize * $denominator;

        $fullQuarters = intdiv($numerator, $unitsPerQuarter);
        $remainder = $numerator % $unitsPerQuarter;

        // Seuil : 7 minutes exprimées dans l'unité du reste (1/$denominator de
        // minute). Au-delà, arrondi supérieur.
        if ($remainder > 7 * $denominator) {
            return ($fullQuarters + 1) * $quarterSize;
        }

        return $fullQuarters * $quarterSize;
    }
}
