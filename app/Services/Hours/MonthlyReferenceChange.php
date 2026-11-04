<?php

namespace App\Services\Hours;

use App\Exceptions\BusinessRuleException;
use App\Support\BusinessTime;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Référence mensuelle individuelle et sa date d'effet.
 *
 * La date d'effet est toujours le premier jour d'un mois civil. Une
 * modification en cours de mois doit donc être enregistrée avec le premier
 * jour du mois suivant.
 */
final class MonthlyReferenceChange
{
    private function __construct(
        private readonly CarbonImmutable $effectiveFrom,
        private readonly int $referenceMinutes,
        private readonly ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * Crée une modification à partir d'une date d'effet explicite.
     *
     * Utilisé par le fournisseur de références et les tests. La date fournie
     * doit déjà être le premier jour d'un mois civil ; aucune normalisation
     * automatique n'est effectuée ici.
     */
    public static function forMonth(CarbonInterface|string $month, int $referenceMinutes): self
    {
        if ($referenceMinutes <= 0) {
            throw new BusinessRuleException('La référence mensuelle doit être strictement positive.');
        }

        $date = is_string($month) ? DatePeriod::parse($month) : $month;

        return new self(
            BusinessTime::toLocal($date)->startOfMonth(),
            $referenceMinutes,
        );
    }

    /**
     * Crée une modification à partir de la date à laquelle elle a été saisie.
     *
     * La date d'effet est automatiquement fixée au premier jour du mois civil
     * suivant la modification. Une modification effectuée le 15 novembre
     * prend donc effet le 1er décembre.
     *
     * Une tentative de création dont l'effet serait antérieur à la date du
     * jour (modification rétroactive) est refusée.
     */
    public static function fromModificationDate(
        CarbonInterface|string $modificationDate,
        int $referenceMinutes,
        ?CarbonInterface $now = null,
    ): self {
        if ($referenceMinutes <= 0) {
            throw new BusinessRuleException('La référence mensuelle doit être strictement positive.');
        }

        $modification = is_string($modificationDate)
            ? DatePeriod::parse($modificationDate)
            : $modificationDate;

        $modificationLocal = BusinessTime::toLocal($modification);
        $effectiveFrom = $modificationLocal->startOfMonth()->addMonth();

        $nowLocal = $now === null
            ? BusinessTime::toLocal(CarbonImmutable::now())
            : BusinessTime::toLocal($now)->startOfDay();

        // L'effet est toujours le mois suivant la modification. Il ne peut pas
        // être antérieur au jour courant, sous peine d'être rétroactif.
        if ($effectiveFrom->lessThan($nowLocal)) {
            throw new BusinessRuleException(
                'Une modification de référence ne peut pas prendre effet avant le mois courant.'
            );
        }

        return new self($effectiveFrom, $referenceMinutes, $nowLocal);
    }

    public function effectiveFrom(): CarbonImmutable
    {
        return $this->effectiveFrom;
    }

    public function referenceMinutes(): int
    {
        return $this->referenceMinutes;
    }

    public function createdAt(): ?CarbonImmutable
    {
        return $this->createdAt;
    }
}
