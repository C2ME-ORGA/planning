<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\TimesheetOverlapReview;
use App\Models\TimesheetSegment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Vue de lecture des chevauchements d'un relevé et de leurs revues.
 *
 * La détection et la décision restent portées par
 * `TimesheetOverlapReviewService`. Cette classe rapproche les paires détectées
 * de l'état courant (`timesheet_overlap_reviews`) et des décisions historisées
 * dans le journal d'audit, afin d'identifier explicitement une revue obsolète.
 */
final class TimesheetOverlapOverview
{
    public function __construct(
        private readonly TimesheetOverlapReviewService $reviews,
        private readonly TimesheetActorHistory $history,
    ) {}

    /**
     * @return array<int, array{
     *     pair_key: string,
     *     first: TimesheetSegment,
     *     second: TimesheetSegment,
     *     fingerprint: string,
     *     state: 'pending'|'accepted'|'rejected'|'stale',
     *     review: TimesheetOverlapReview|null,
     *     needs_review: bool,
     *     stale: bool,
     *     decisions: array<int, array{
     *         occurred_at: CarbonImmutable,
     *         actor: string|null,
     *         decision: string|null,
     *         reason: string|null
     *     }>
     * }>
     */
    public function forTimesheet(Timesheet $timesheet): array
    {
        $detected = $this->reviews->overlapsFor($timesheet);

        if ($detected === []) {
            return [];
        }

        $segmentIds = [];

        foreach ($detected as $overlap) {
            $segmentIds[] = $overlap['first_segment_id'];
            $segmentIds[] = $overlap['second_segment_id'];
        }

        /** @var Collection<int, TimesheetSegment> $segments */
        $segments = TimesheetSegment::query()
            ->whereKey(array_values(array_unique($segmentIds)))
            ->with('timesheet')
            ->get()
            ->keyBy(static fn (TimesheetSegment $segment): int => (int) $segment->getKey());

        $cycle = $this->history->currentCycleSequence($timesheet);

        /** @var Collection<string, TimesheetOverlapReview> $reviews */
        $reviews = TimesheetOverlapReview::query()
            ->where('timesheet_id', $timesheet->getKey())
            ->get()
            ->keyBy('pair_key');

        $decisions = $this->decisionsFor($timesheet);

        $entries = [];

        foreach ($detected as $overlap) {
            $first = $segments->get($overlap['first_segment_id']);
            $second = $segments->get($overlap['second_segment_id']);

            if (! $first instanceof TimesheetSegment || ! $second instanceof TimesheetSegment) {
                continue;
            }

            $review = $reviews->get($overlap['pair_key']);
            $stale = $review instanceof TimesheetOverlapReview
                && ($review->cycle_sequence !== $cycle
                    || $review->fingerprint !== $overlap['fingerprint']);

            $state = match (true) {
                ! $review instanceof TimesheetOverlapReview => 'pending',
                $stale => 'stale',
                default => $review->decision->value,
            };

            $entries[] = [
                'pair_key' => $overlap['pair_key'],
                'first' => $first,
                'second' => $second,
                'fingerprint' => $overlap['fingerprint'],
                'state' => $state,
                'review' => $review,
                'needs_review' => $state === 'pending' || $state === 'stale',
                'stale' => $stale,
                'decisions' => $decisions[$overlap['pair_key']] ?? [],
            ];
        }

        return $entries;
    }

    public function pendingPairCount(Timesheet $timesheet): int
    {
        return count(array_filter(
            $this->forTimesheet($timesheet),
            static fn (array $overlap): bool => $overlap['needs_review'],
        ));
    }

    /**
     * Relevés dont le statut autorise une revue et qui possèdent au moins une
     * paire non examinée ou dont la revue est obsolète.
     *
     * @return Collection<int, Timesheet>
     */
    public function timesheetsWithPendingOverlaps(): Collection
    {
        $statuses = array_values(array_filter(
            TimesheetStatus::cases(),
            static fn (TimesheetStatus $status): bool => $status->allowsOverlapReview(),
        ));

        return Timesheet::query()
            ->whereIn('status', array_map(
                static fn (TimesheetStatus $status): string => $status->value,
                $statuses,
            ))
            ->with(['employee', 'segments'])
            ->orderByDesc('period_start')
            ->get()
            ->filter(fn (Timesheet $timesheet): bool => $this->pendingPairCount($timesheet) > 0)
            ->values();
    }

    /**
     * @return array<string, array<int, array{
     *     occurred_at: CarbonImmutable,
     *     actor: string|null,
     *     decision: string|null,
     *     reason: string|null
     * }>>
     */
    private function decisionsFor(Timesheet $timesheet): array
    {
        $events = AuditEvent::query()
            ->where('subject_type', Timesheet::class)
            ->where('subject_id', (string) $timesheet->getKey())
            ->whereIn('action', [
                AuditAction::TimesheetOverlapReviewAccepted->value,
                AuditAction::TimesheetOverlapReviewRejected->value,
            ])
            ->with('actor')
            ->orderByDesc('sequence')
            ->get();

        $decisions = [];

        foreach ($events as $event) {
            $pairKey = $event->changes['pair_key'] ?? null;

            if (! is_string($pairKey)) {
                continue;
            }

            $decision = $event->changes['decision'] ?? null;

            $decisions[$pairKey][] = [
                'occurred_at' => BusinessTime::toLocal($event->occurred_at),
                'actor' => $event->actor?->name,
                'decision' => is_string($decision)
                    ? OverlapReviewDecision::tryFrom($decision)?->label()
                    : null,
                'reason' => $event->reason,
            ];
        }

        return $decisions;
    }
}
