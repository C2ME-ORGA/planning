<?php

namespace App\Services\Hours;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\DatePeriod;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Composition des calculs officiels à partir des relevés validés.
 *
 * Le service ne contient aucune règle de calcul propre : il sélectionne les
 * relevés validés, aplatit leurs segments puis délègue aux calculateurs purs.
 *
 * Il ne vérifie pas de permission dans ses méthodes internes : tout appel
 * exposé à un utilisateur doit utiliser les méthodes avec acteur, qui
 * appliquent le périmètre de lecture de `TimesheetQuery`.
 */
final class OfficialHoursCalculator
{
    public function __construct(
        private readonly ValidatedTimesheetSelector $selector,
        private readonly WeeklyHoursCalculator $weekly,
        private readonly MonthlyHoursAggregator $monthly,
    ) {}

    /**
     * Calcul hebdomadaire interne, sans contrôle d'autorisation.
     *
     * Réservé aux usages internes déjà autorisés. Pour une exposition
     * utilisateur, utiliser `weeklyForActor()`.
     *
     * @return array<string, mixed>
     */
    public function weeklyForEmployee(Employee $employee, CarbonInterface|string $week): array
    {
        $bounds = Timesheet::weekFor($week);

        $timesheets = $this->selector->forEmployee(
            $employee,
            $bounds['start'],
            $bounds['end']->addDay(),
        );

        return $this->weekly->calculate($this->segments($timesheets), $bounds['start']);
    }

    /**
     * Calcul hebdomadaire soumis au périmètre de lecture de l'acteur.
     *
     * L'acteur doit disposer de la permission de consultation des relevés
     * (`TimesheetOwn`, `TimesheetControl` ou `TimesheetValidate`) et, le cas
     * échéant, d'un périmètre couvrant le salarié cible.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException si l'acteur ne
     *                                peut pas consulter les relevés du salarié.
     */
    public function weeklyForActor(
        User $actor,
        Employee $employee,
        CarbonInterface|string $week,
    ): array {
        Gate::forUser($actor)->authorize('viewAny', Timesheet::class);

        $bounds = Timesheet::weekFor($week);

        $timesheets = $this->selector->visibleFor(
            $actor,
            $employee,
            $bounds['start'],
            $bounds['end']->addDay(),
        );

        return $this->weekly->calculate($this->segments($timesheets), $bounds['start']);
    }

    /**
     * Calcul mensuel interne, sans contrôle d'autorisation.
     *
     * Réservé aux usages internes déjà autorisés. Pour une exposition
     * utilisateur, utiliser `monthlyForActor()`.
     *
     * @return array<string, mixed>
     */
    public function monthlyForEmployee(Employee $employee, CarbonInterface|string $month): array
    {
        $date = is_string($month) ? DatePeriod::parse($month) : $month;
        $monthStart = BusinessTime::toLocal($date)->startOfMonth();

        $timesheets = $this->selector->forEmployee(
            $employee,
            $monthStart,
            $monthStart->addMonth(),
        );

        return $this->monthly->calculate(
            $employee,
            $this->segments($timesheets),
            $monthStart,
        );
    }

    /**
     * Calcul mensuel soumis au périmètre de lecture de l'acteur.
     *
     * L'acteur doit disposer de la permission de consultation des relevés
     * (`TimesheetOwn`, `TimesheetControl` ou `TimesheetValidate`) et, le cas
     * échéant, d'un périmètre couvrant le salarié cible.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException si l'acteur ne
     *                                peut pas consulter les relevés du salarié.
     */
    public function monthlyForActor(
        User $actor,
        Employee $employee,
        CarbonInterface|string $month,
    ): array {
        Gate::forUser($actor)->authorize('viewAny', Timesheet::class);

        $date = is_string($month) ? DatePeriod::parse($month) : $month;
        $monthStart = BusinessTime::toLocal($date)->startOfMonth();

        $timesheets = $this->selector->visibleFor(
            $actor,
            $employee,
            $monthStart,
            $monthStart->addMonth(),
        );

        return $this->monthly->calculate(
            $employee,
            $this->segments($timesheets),
            $monthStart,
        );
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     * @return array<int, TimesheetSegment>
     */
    private function segments(Collection $timesheets): array
    {
        $segments = [];

        foreach ($timesheets as $timesheet) {
            foreach ($timesheet->segments as $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }
}
