<?php

namespace App\Services\Counter;

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use App\Models\CounterMovement;
use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Calcul du solde du compteur à partir du journal des mouvements.
 *
 * Le solde est toujours reconstitué à chaud depuis les mouvements
 * enregistrés. Aucune colonne de solde courant n'est stockée sur le
 * salarié, et aucun snapshot n'est créé dans PC1-35.
 */
final class CounterBalanceService
{
    /**
     * Reconstitue le solde à une date donnée (ou à ce jour si null).
     *
     * Les mouvements sont triés par `occurred_at`, puis par `id` pour
     * garantir un ordre déterministe.
     *
     * @return int Solde en minutes, toujours positif ou nul.
     */
    public function balanceFor(Employee $employee, ?CarbonInterface $asOf = null): int
    {
        $query = CounterMovement::query()
            ->where('employee_id', $employee->getKey())
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc');

        if ($asOf !== null) {
            $query->where('occurred_at', '<=', $asOf);
        }

        return $query->get()->reduce(
            fn (int $carry, CounterMovement $movement): int => $carry
                + $this->signedVolume($movement),
            0,
        );
    }

    private function signedVolume(CounterMovement $movement): int
    {
        $type = $movement->type;
        $volume = (int) $movement->volume_minutes;

        // L'utilisateur final ne peut créer que des volumes positifs. Le
        // sens du mouvement est porté par le type ; l'ajustement précise
        // sa direction dans sa colonne dédiée.
        return match ($type) {
            CounterMovementType::Credit => $volume,
            CounterMovementType::Adjustment =>
                $movement->direction === CounterAdjustmentDirection::Credit
                    ? $volume
                    : -$volume,
            default => -$volume,
        };
    }
}
