<?php

namespace App\Services\Hours;

use App\Enums\TimeCategory;
use App\Models\Employee;
use App\Models\TimesheetSegment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Agrégats mensuels du travail effectif et des autres catégories.
 *
 * Le mois civil est ventilé par date locale française. Une semaine à cheval
 * sur deux mois est donc répartie sur ses dates réelles, sans modifier le
 * calcul hebdomadaire. Chaque catégorie est fusionnée séparément : les
 * catégories différentes ne sont jamais additionnées entre elles.
 *
 * Le résultat reste informatif : aucun solde négatif, aucun mouvement de
 * compteur et aucune retenue ne sont créés par ce service.
 */
final class MonthlyHoursAggregator
{
    public function __construct(
        private readonly CategoryIntervals $intervals,
        private readonly MonthlyReferenceResolver $references,
        private readonly EmploymentPresence $presence,
    ) {}

    /**
     * @param  iterable<TimesheetSegment>  $segments
     * @return array{
     *     month: string,
     *     month_start: string,
     *     month_end: string,
     *     effective_minutes: int,
     *     by_day: array<string, int>,
     *     by_category: array<string, array{
     *         minutes: int,
     *         by_day: array<string, int>,
     *         overlaps: array<int, array{
     *             segment_ids: array<int, int>,
     *             start_at: CarbonImmutable,
     *             end_at: CarbonImmutable,
     *             minutes: int
     *         }>
     *     }>,
     *     overlaps: array<int, array{
     *         segment_ids: array<int, int>,
     *         start_at: CarbonImmutable,
     *         end_at: CarbonImmutable,
     *         minutes: int
     *     }>,
     *     presence_days: int,
     *     days_in_month: int,
     *     reference_applicable: bool,
     *     reference_base_minutes: int|null,
     *     reference_minutes: int|null,
     *     reference_exact_numerator: int|null,
     *     reference_exact_denominator: int|null,
     *     reference_prorated: bool,
     *     difference_minutes: int|null,
     *     credit_candidate_minutes: int|null,
     *     deficit_minutes: int|null,
     * }
     */
    public function calculate(
        Employee $employee,
        iterable $segments,
        CarbonInterface $month,
    ): array {
        $monthStart = BusinessTime::toLocal($month)->startOfMonth();
        $monthEnd = $monthStart->addMonth();

        /** @var array<int, TimesheetSegment> $segmentList */
        $segmentList = [];

        foreach ($segments as $segment) {
            $segmentList[] = $segment;
        }

        $effective = $this->intervals->build(
            TimeCategory::TravailEffectif,
            $segmentList,
            $monthStart,
            $monthEnd,
        );

        $byCategory = [];

        foreach (TimeCategory::cases() as $category) {
            $categoryResult = $category === TimeCategory::TravailEffectif
                ? $effective
                : $this->intervals->build($category, $segmentList, $monthStart, $monthEnd);

            $byCategory[$category->value] = [
                'minutes' => $categoryResult['total_minutes'],
                'by_day' => $categoryResult['daily_minutes'],
                'overlaps' => $categoryResult['overlaps'],
            ];
        }

        $presence = $this->presence->forEmployee($employee, $monthStart);
        $referenceApplicable = $presence['has_employment'];

        $referenceBaseMinutes = null;
        $referenceMinutes = null;
        $referenceExactNumerator = null;
        $referenceExactDenominator = null;
        $referenceProrated = false;
        $differenceMinutes = null;
        $creditCandidateMinutes = null;
        $deficitMinutes = null;

        if ($referenceApplicable) {
            $referenceBaseMinutes = $this->references->baseMinutesFor($employee, $monthStart);
            $reference = ProratedReference::calculate(
                $referenceBaseMinutes,
                $presence['presence_days'],
                $presence['days_in_month'],
            );

            $referenceMinutes = $reference['reference_minutes'];
            $referenceExactNumerator = $reference['exact_numerator'];
            $referenceExactDenominator = $reference['exact_denominator'];
            $referenceProrated = $reference['is_prorated'];
            $differenceMinutes = $effective['total_minutes'] - $referenceMinutes;
            $creditCandidateMinutes = max(0, $differenceMinutes);
            $deficitMinutes = max(0, $differenceMinutes * -1);
        }

        return [
            'month' => $monthStart->format('Y-m'),
            'month_start' => $monthStart->toDateString(),
            'month_end' => $monthEnd->subDay()->toDateString(),
            'effective_minutes' => $effective['total_minutes'],
            'by_day' => $effective['daily_minutes'],
            'by_category' => $byCategory,
            'overlaps' => $effective['overlaps'],
            'presence_days' => $presence['presence_days'],
            'days_in_month' => $presence['days_in_month'],
            'reference_applicable' => $referenceApplicable,
            'reference_base_minutes' => $referenceBaseMinutes,
            'reference_minutes' => $referenceMinutes,
            'reference_exact_numerator' => $referenceExactNumerator,
            'reference_exact_denominator' => $referenceExactDenominator,
            'reference_prorated' => $referenceProrated,
            'difference_minutes' => $differenceMinutes,
            'credit_candidate_minutes' => $creditCandidateMinutes,
            'deficit_minutes' => $deficitMinutes,
        ];
    }
}
