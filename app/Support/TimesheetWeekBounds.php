<?php

namespace App\Support;

use App\Models\Timesheet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Bornes temporelles d'une semaine de relevé.
 *
 * Le relevé couvre une semaine civile du lundi au dimanche. Les bornes sont
 * construites dans le fuseau métier `Europe/Paris` puis comparées en UTC : un
 * segment peut traverser minuit à l'intérieur de la semaine, mais il ne peut
 * pas franchir la limite de semaine. La dernière minute acceptée est le
 * dimanche 23:59 ; le lundi 00:00 suivant est hors semaine.
 *
 * Cette classe est l'unique source de la règle, partagée par l'écriture des
 * segments et par la revalidation effectuée lors de la transmission.
 */
final class TimesheetWeekBounds
{
    /**
     * @return array{lower: CarbonImmutable, upper: CarbonImmutable}
     */
    public static function for(Timesheet $timesheet): array
    {
        $periodStart = $timesheet->period_start;
        $periodEnd = $timesheet->period_end;

        if ($periodStart === null || $periodEnd === null) {
            throw new LogicException('Un relevé doit avoir une période complète pour accepter un segment.');
        }

        return [
            'lower' => CarbonImmutable::parse(
                $periodStart->toDateString().' 00:00:00',
                BusinessTime::TIMEZONE,
            )->utc(),
            'upper' => CarbonImmutable::parse(
                $periodEnd->toDateString().' 23:59:00',
                BusinessTime::TIMEZONE,
            )->utc(),
        ];
    }

    /**
     * Refuse un segment dont le début ou la fin sort de la semaine du relevé.
     * La valeur reçue n'est jamais modifiée.
     */
    public static function assertContains(
        Timesheet $timesheet,
        CarbonInterface $start,
        CarbonInterface $end,
        string $field = 'start_at',
    ): void {
        $bounds = self::for($timesheet);
        $startUtc = CarbonImmutable::instance($start)->utc();
        $endUtc = CarbonImmutable::instance($end)->utc();

        if ($startUtc->lessThan($bounds['lower'])
            || $startUtc->greaterThan($bounds['upper'])
            || $endUtc->greaterThan($bounds['upper'])) {
            throw ValidationException::withMessages([
                $field => 'Le segment doit rester dans la semaine du relevé, du lundi au dimanche.',
            ]);
        }
    }
}
