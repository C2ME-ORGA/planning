<?php

namespace App\Services\Hours;

use App\Enums\TimesheetStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetQuery;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sélection des relevés officiels d'un salarié sur une période.
 *
 * Seuls les relevés actuellement validés sont retournés. Le filtrage est
 * effectué en SQL, avant tout calcul.
 *
 * La fenêtre est exprimée en dates civiles locales : `start` est inclus au
 * premier jour, `end` est exclusive et désignée comme le premier jour hors
 * fenêtre (équivalent à `[start, end)` au niveau des dates civiles). Les
 * appelants passent généralement le premier jour de la semaine ou du mois
 * pour `start`, et le premier jour suivant pour `end`.
 */
final class ValidatedTimesheetSelector
{
    public function __construct(
        private readonly TimesheetQuery $timesheetQuery,
    ) {}

    /**
     * @return Collection<int, Timesheet>
     */
    public function forEmployee(
        Employee $employee,
        CarbonInterface $start,
        CarbonInterface $end,
    ): Collection {
        return $this->apply(
            Timesheet::query()->where('employee_id', $employee->getKey()),
            $start,
            $end,
        );
    }

    /**
     * Variante soumise au périmètre de lecture de l'acteur.
     *
     * @return Collection<int, Timesheet>
     */
    public function visibleFor(
        User $actor,
        Employee $employee,
        CarbonInterface $start,
        CarbonInterface $end,
    ): Collection {
        return $this->apply(
            $this->timesheetQuery->visibleTo($actor)
                ->where('employee_id', $employee->getKey()),
            $start,
            $end,
        );
    }

    /**
     * @param  Builder<Timesheet>  $query
     * @return Collection<int, Timesheet>
     */
    private function apply(
        Builder $query,
        CarbonInterface $start,
        CarbonInterface $end,
    ): Collection {
        $startLocal = BusinessTime::toLocal($start)->startOfDay();
        $endLocal = BusinessTime::toLocal($end)->startOfDay();

        if ($endLocal->lessThanOrEqualTo($startLocal)) {
            throw new BusinessRuleException('La période de calcul doit être strictement positive.');
        }

        $startDate = $startLocal->toDateString();
        $endDate = $endLocal->toDateString();
        $startUtc = $startLocal->utc()->format('Y-m-d H:i:s');
        $endUtc = $endLocal->utc()->format('Y-m-d H:i:s');

        return $query
            ->where('status', TimesheetStatus::Validee->value)
            ->where('period_start', '<', $endDate)
            ->where('period_end', '>=', $startDate)
            ->with(['segments' => function (HasMany $segments) use ($startUtc, $endUtc): void {
                $segments
                    ->where('start_at', '<', $endUtc)
                    ->where('end_at', '>', $startUtc);
            }])
            ->orderBy('period_start')
            ->get();
    }
}
