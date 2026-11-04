<?php

namespace App\Services\Hours;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;

/**
 * Résolution de la référence mensuelle applicable à un mois civil.
 *
 * Seul un changement dont la date d'effet est antérieure ou égale au premier
 * jour du mois est retenu. Pour un même mois d'effet, le dernier changement
 * fourni par le provider remplace le précédent : une modification future peut
 * donc remplacer une autre modification future, sans effet rétroactif.
 *
 * La résolution est déterministe : elle ne dépend pas de l'ordre de retour du
 * provider. Les changements sont triés par date d'effet décroissante, puis
 * par date de création décroissante lorsque celle-ci est disponible.
 */
final class MonthlyReferenceResolver
{
    public const DEFAULT_REFERENCE_MINUTES = 10140;

    public function __construct(
        private readonly MonthlyReferenceProvider $provider,
        private readonly int $defaultReferenceMinutes = self::DEFAULT_REFERENCE_MINUTES,
    ) {
        if ($this->defaultReferenceMinutes <= 0) {
            throw new BusinessRuleException('La référence mensuelle par défaut doit être strictement positive.');
        }
    }

    public function baseMinutesFor(Employee $employee, CarbonInterface $month): int
    {
        $monthStart = BusinessTime::toLocal($month)->startOfMonth();
        $applicable = null;

        $changes = iterator_to_array($this->provider->changesFor($employee));

        // Tri déterministe : date d'effet décroissante. Pour deux changements
        // ayant la même date d'effet, le dernier fourni par le provider
        // (index le plus élevé) l'emporte. Cela évite toute dépendance à un
        // tri implicite en base ou à l'ordre de retour d'un provider Eloquent.
        $indexed = array_map(
            static fn (MonthlyReferenceChange $change, int $index): array => [
                'change' => $change,
                'index' => $index,
            ],
            $changes,
            array_keys($changes),
        );

        usort($indexed, static function (array $first, array $second): int {
            return $second['change']->effectiveFrom()->getTimestamp()
                <=> $first['change']->effectiveFrom()->getTimestamp()
                ?: $second['index'] <=> $first['index'];
        });

        foreach ($indexed as $entry) {
            if ($entry['change']->effectiveFrom()->greaterThan($monthStart)) {
                continue;
            }

            $applicable = $entry['change'];
            break;
        }

        if ($applicable !== null) {
            return $applicable->referenceMinutes();
        }

        return $this->defaultReferenceMinutes;
    }
}
