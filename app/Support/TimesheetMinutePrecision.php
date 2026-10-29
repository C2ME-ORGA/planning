<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Précision temporelle d'un segment de relevé.
 *
 * Un segment est saisi à la minute entière : ni seconde, ni fraction de
 * seconde. Cette règle est partagée par l'écriture des segments, le garde du
 * modèle et la revalidation effectuée lors de la transmission, afin qu'une
 * donnée insérée hors service (import, écriture directe) ne puisse pas être
 * transmise si elle ne respecte pas la précision métier.
 */
final class TimesheetMinutePrecision
{
    public static function isRespected(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $start->second === 0
            && $end->second === 0
            && $start->micro === 0
            && $end->micro === 0;
    }

    public static function assert(CarbonInterface $start, CarbonInterface $end, string $field = 'start_at'): void
    {
        if (self::isRespected($start, $end)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Un segment est saisi à la minute, sans seconde ni fraction.',
        ]);
    }
}
