<?php

namespace App\Support;

use App\Enums\TimeCategory;
use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Intervalle temporel métier en UTC, semi-ouvert `[start, end)`.
 *
 * Les instants sont manipulés en UTC pour éviter toute ambiguïté autour des
 * changements d'heure. La projection en date civile française reste confiée à
 * `WorkedTimeCalculator` et `BusinessTime`.
 */
final class TimeInterval
{
    private function __construct(
        private readonly CarbonImmutable $startAt,
        private readonly CarbonImmutable $endAt,
        private readonly ?int $segmentId,
        private readonly ?int $timesheetId = null,
        private readonly ?TimeCategory $category = null,
    ) {}

    public static function between(
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $segmentId = null,
        ?int $timesheetId = null,
        ?TimeCategory $category = null,
    ): self {
        $startUtc = CarbonImmutable::instance($start)->utc();
        $endUtc = CarbonImmutable::instance($end)->utc();

        if ($endUtc->lessThanOrEqualTo($startUtc)) {
            throw new BusinessRuleException(
                'La fin d’un intervalle doit être strictement postérieure à son début.',
            );
        }

        if ($startUtc->second !== 0 || $startUtc->micro !== 0
            || $endUtc->second !== 0 || $endUtc->micro !== 0) {
            throw new BusinessRuleException('Un intervalle horaire est exprimé à la minute entière.');
        }

        return new self($startUtc, $endUtc, $segmentId, $timesheetId, $category);
    }

    public function start(): CarbonImmutable
    {
        return $this->startAt;
    }

    public function end(): CarbonImmutable
    {
        return $this->endAt;
    }

    public function segmentId(): ?int
    {
        return $this->segmentId;
    }

    public function timesheetId(): ?int
    {
        return $this->timesheetId;
    }

    public function category(): ?TimeCategory
    {
        return $this->category;
    }

    public function minutes(): int
    {
        return intdiv($this->endAt->getTimestamp() - $this->startAt->getTimestamp(), 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->startAt->lessThan($other->endAt)
            && $other->startAt->lessThan($this->endAt);
    }

    public function isAdjacentTo(self $other): bool
    {
        return $this->endAt->equalTo($other->startAt)
            || $other->endAt->equalTo($this->startAt);
    }

    /**
     * Intersection avec une fenêtre de calcul, ou `null` si elle est vide.
     *
     * La précision à la minute est conservée et validée, comme pour
     * `between()`.
     */
    public function clip(CarbonInterface $from, CarbonInterface $to): ?self
    {
        $fromUtc = CarbonImmutable::instance($from)->utc();
        $toUtc = CarbonImmutable::instance($to)->utc();

        if ($toUtc->lessThanOrEqualTo($fromUtc)) {
            throw new BusinessRuleException('La fenêtre de calcul doit être strictement positive.');
        }

        $start = $this->startAt->greaterThan($fromUtc) ? $this->startAt : $fromUtc;
        $end = $this->endAt->lessThan($toUtc) ? $this->endAt : $toUtc;

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        if ($start->second !== 0 || $start->micro !== 0
            || $end->second !== 0 || $end->micro !== 0) {
            throw new BusinessRuleException('Un intervalle horaire est exprimé à la minute entière.');
        }

        return new self($start, $end, $this->segmentId, $this->timesheetId, $this->category);
    }

    /**
     * Union de deux intervalles qui se chevauchent ou sont adjacents.
     *
     * L'identifiant de segment, le relevé et la catégorie sont réinitialisés
     * car l'intervalle résultant ne correspond plus à un seul segment source.
     */
    public function mergeWith(self $other): self
    {
        return new self(
            $this->startAt->lessThan($other->startAt) ? $this->startAt : $other->startAt,
            $this->endAt->greaterThan($other->endAt) ? $this->endAt : $other->endAt,
            null,
            null,
            null,
        );
    }
}
