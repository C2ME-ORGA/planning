<?php

namespace App\Services\Hours;

use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours calendaires de présence d'un salarié dans un mois civil.
 *
 * Les périodes d'emploi sont inclusives. Plusieurs périodes dans le même mois
 * sont réunies sans double comptage. Aucune absence, congé ou jour férié ne
 * réduit la présence : cette règle n'est pas portée par PC1-34.
 */
final class EmploymentPresence
{
    /**
     * @return array{presence_days: int, days_in_month: int, has_employment: bool}
     */
    public function forEmployee(Employee $employee, CarbonInterface $month): array
    {
        // La relation doit être rechargée intégralement, même si elle a déjà
        // été chargée avec une contrainte. Une relation partiellement chargée
        // ne doit jamais fausser le calcul officiel.
        $periods = $employee->employmentPeriods()->get();

        return $this->forPeriods($periods, $month);
    }

    /**
     * @param  iterable<EmploymentPeriod>  $periods
     * @return array{presence_days: int, days_in_month: int, has_employment: bool}
     */
    public function forPeriods(iterable $periods, CarbonInterface $month): array
    {
        $ranges = [];

        foreach ($periods as $period) {
            $ranges[] = [
                'start' => $period->start_date->toDateString(),
                'end' => $period->end_date?->toDateString(),
            ];
        }

        return $this->forRanges($ranges, $month);
    }

    /**
     * Même calcul à partir de plages de dates pures, sans dépendance Eloquent.
     *
     * @param  iterable<array{start: string, end: string|null}>  $ranges
     * @return array{presence_days: int, days_in_month: int, has_employment: bool}
     */
    public function forRanges(iterable $ranges, CarbonInterface $month): array
    {
        $monthStart = BusinessTime::toLocal($month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $presentDays = [];

        foreach ($ranges as $range) {
            $periodStart = CarbonImmutable::parse($range['start']);
            $periodEnd = $range['end'] === null
                ? null
                : CarbonImmutable::parse($range['end']);

            $from = $periodStart->greaterThan($monthStart) ? $periodStart : $monthStart;
            $to = $periodEnd === null || $periodEnd->greaterThan($monthEnd)
                ? $monthEnd
                : $periodEnd;

            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $presentDays[$day->toDateString()] = true;
            }
        }

        return [
            'presence_days' => count($presentDays),
            'days_in_month' => $monthStart->daysInMonth,
            'has_employment' => $presentDays !== [],
        ];
    }
}
