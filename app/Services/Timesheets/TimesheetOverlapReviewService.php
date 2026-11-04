<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Models\Timesheet;
use App\Models\TimesheetOverlapReview;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetReason;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Revue persistante des chevauchements d’un relevé.
 *
 * La détection existante reste la source de vérité : chaque paire de segments
 * est identifiée de façon canonique (`min:max`), quel que soit l’ordre de
 * découverte. L’empreinte couvre les deux segments et leurs relevés, ce qui
 * invalide une revue dès qu’une donnée métier change. Le cycle est la séquence
 * du dernier événement `timesheet.reopened` ou
 * `timesheet.correction_resumed`, afin qu’un nouveau cycle de correction exige
 * systématiquement une nouvelle revue.
 *
 * La table `timesheet_overlap_reviews` ne porte que l’état applicable courant.
 * L’historique immuable est écrit dans le journal d’audit.
 */
final class TimesheetOverlapReviewService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TimesheetActorHistory $history,
    ) {}

    /**
     * Paires de segments actuellement en chevauchement pour ce relevé.
     *
     * Une paire est retenue dès que l’un de ses segments appartient au relevé.
     * Les doublons de détection sont éliminés par la clé canonique.
     *
     * @return array<int, array{
     *     pair_key: string,
     *     first_segment_id: int,
     *     second_segment_id: int,
     *     fingerprint: string
     * }>
     */
    public function overlapsFor(Timesheet $timesheet): array
    {
        return $this->overlapsForTimesheets([$timesheet])[(int) $timesheet->getKey()] ?? [];
    }

    /**
     * Paires de chevauchement pour plusieurs relevés, en une passe groupée.
     *
     * @param  array<int, Timesheet>  $timesheets
     * @return array<int, array<int, array{
     *     pair_key: string,
     *     first_segment_id: int,
     *     second_segment_id: int,
     *     fingerprint: string
     * }>>
     */
    public function overlapsForTimesheets(array $timesheets): array
    {
        $byId = [];

        foreach ($timesheets as $timesheet) {
            $byId[(int) $timesheet->getKey()] = $timesheet;
        }

        if ($byId === []) {
            return [];
        }

        $pairs = $this->overlappingPairIds(array_keys($byId));
        $ids = array_map(static fn (int|string $id): int => (int) $id, array_keys($byId));
        $detected = [];

        foreach ($ids as $id) {
            $detected[$id] = [];
        }

        if ($pairs === []) {
            return $detected;
        }

        $segmentIds = [];

        foreach ($pairs as [, $firstId, $secondId]) {
            $segmentIds[] = $firstId;
            $segmentIds[] = $secondId;
        }

        /** @var Collection<int, TimesheetSegment> $segments */
        $segments = TimesheetSegment::query()
            ->whereKey(array_values(array_unique($segmentIds)))
            ->with('timesheet')
            ->get()
            ->keyBy(static fn (TimesheetSegment $segment): int => (int) $segment->getKey());

        foreach ($pairs as [$ownTimesheetId, $firstId, $secondId]) {
            if (! array_key_exists($ownTimesheetId, $detected)) {
                continue;
            }

            [$firstId, $secondId] = self::orderedIds($firstId, $secondId);
            $pairKey = self::pairKey($firstId, $secondId);

            if (array_key_exists($pairKey, $detected[$ownTimesheetId])) {
                continue;
            }

            $first = $segments->get($firstId);
            $second = $segments->get($secondId);

            if (! $first instanceof TimesheetSegment || ! $second instanceof TimesheetSegment) {
                continue;
            }

            $detected[$ownTimesheetId][$pairKey] = [
                'pair_key' => $pairKey,
                'first_segment_id' => $firstId,
                'second_segment_id' => $secondId,
                'fingerprint' => $this->fingerprint($first, $second),
            ];
        }

        $result = [];

        foreach ($detected as $timesheetId => $entries) {
            ksort($entries);
            $result[(int) $timesheetId] = array_values($entries);
        }

        return $result;
    }

    /**
     * Paires de segments en chevauchement pour plusieurs relevés, en une requête.
     *
     * Les prédicats reproduisent exactement `TimesheetOverlapDetector` :
     * même salarié, segments distincts, chevauchement strict, toutes
     * catégories et tous relevés confondus (y compris le même relevé).
     *
     * @param  array<int, int>  $timesheetIds
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    private function overlappingPairIds(array $timesheetIds): array
    {
        return DB::table('timesheet_segments as own_segment')
            ->join('timesheets as own_timesheet', 'own_timesheet.id', '=', 'own_segment.timesheet_id')
            ->join('timesheets as other_timesheet', function (JoinClause $join): void {
                $join->on('other_timesheet.employee_id', '=', 'own_timesheet.employee_id');
            })
            ->join('timesheet_segments as other_segment', function (JoinClause $join): void {
                $join->on('other_segment.timesheet_id', '=', 'other_timesheet.id')
                    ->whereColumn('other_segment.id', '!=', 'own_segment.id')
                    ->whereColumn('other_segment.start_at', '<', 'own_segment.end_at')
                    ->whereColumn('other_segment.end_at', '>', 'own_segment.start_at');
            })
            ->whereIn('own_timesheet.id', $timesheetIds)
            ->get([
                'own_timesheet.id as own_timesheet_id',
                'own_segment.id as own_segment_id',
                'other_segment.id as other_segment_id',
            ])
            ->map(static fn (object $row): array => [
                (int) $row->own_timesheet_id,
                (int) $row->own_segment_id,
                (int) $row->other_segment_id,
            ])
            ->all();
    }

    /**
     * Clé canonique et stable d’une paire de segments, indépendante de l’ordre
     * de détection.
     */
    public static function pairKey(int $firstSegmentId, int $secondSegmentId): string
    {
        [$first, $second] = self::orderedIds($firstSegmentId, $secondSegmentId);

        return sprintf('%d:%d', $first, $second);
    }

    /**
     * Enregistre une décision motivée et son événement d’audit dans la même
     * transaction que la mise à jour de l’état courant.
     */
    public function review(
        Timesheet $timesheet,
        int $firstSegmentId,
        int $secondSegmentId,
        OverlapReviewDecision $decision,
        string $reason,
        User $actor,
    ): TimesheetOverlapReview {
        return DB::transaction(function () use (
            $timesheet,
            $firstSegmentId,
            $secondSegmentId,
            $decision,
            $reason,
            $actor,
        ): TimesheetOverlapReview {
            // Même ordre de verrouillage que les écritures de segments :
            // d’abord le relevé parent, puis les segments concernés.
            $locked = Timesheet::query()
                ->whereKey($timesheet->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('reviewOverlap', $locked);

            $normalizedReason = TimesheetReason::required($reason);

            $pairKey = self::pairKey($firstSegmentId, $secondSegmentId);
            $overlap = $this->findOverlap($locked, $pairKey);

            if ($overlap === null) {
                throw ValidationException::withMessages([
                    'overlap' => 'Ce chevauchement n’est plus détecté sur ce relevé.',
                ]);
            }

            TimesheetSegment::query()
                ->whereKey([
                    $overlap['first_segment_id'],
                    $overlap['second_segment_id'],
                ])
                ->lockForUpdate()
                ->get();

            $cycleSequence = $this->history->currentCycleSequence($locked);

            $review = TimesheetOverlapReview::query()->updateOrCreate(
                [
                    'timesheet_id' => $locked->getKey(),
                    'pair_key' => $pairKey,
                ],
                [
                    'first_segment_id' => $overlap['first_segment_id'],
                    'second_segment_id' => $overlap['second_segment_id'],
                    'cycle_sequence' => $cycleSequence,
                    'fingerprint' => $overlap['fingerprint'],
                    'decision' => $decision,
                    'reason' => $normalizedReason,
                    'reviewed_by_id' => $actor->getKey(),
                    'reviewed_at' => now(),
                ],
            );

            $this->audit->record(
                $decision === OverlapReviewDecision::Accepted
                    ? AuditAction::TimesheetOverlapReviewAccepted
                    : AuditAction::TimesheetOverlapReviewRejected,
                $locked,
                [
                    'pair_key' => $pairKey,
                    'first_segment_id' => $overlap['first_segment_id'],
                    'second_segment_id' => $overlap['second_segment_id'],
                    'decision' => $decision->value,
                    'cycle_sequence' => $cycleSequence,
                    'fingerprint' => $overlap['fingerprint'],
                ],
                $normalizedReason,
                $actor,
            );

            return $review;
        });
    }

    /**
     * Vérifie que tous les chevauchements actuellement détectés possèdent une
     * revue acceptée, à jour, pour le cycle courant. Toute anomalie bloque la
     * validation finale.
     *
     * @throws ValidationException
     */
    public function assertReadyForValidation(Timesheet $timesheet): void
    {
        $overlaps = $this->overlapsFor($timesheet);

        if ($overlaps === []) {
            return;
        }

        $cycleSequence = $this->history->currentCycleSequence($timesheet);

        /** @var Collection<string, TimesheetOverlapReview> $reviews */
        $reviews = TimesheetOverlapReview::query()
            ->where('timesheet_id', $timesheet->getKey())
            ->get()
            ->keyBy('pair_key');

        foreach ($overlaps as $overlap) {
            $review = $reviews->get($overlap['pair_key']);

            if (! $review instanceof TimesheetOverlapReview) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Un chevauchement non examiné bloque la validation du relevé.',
                ]);
            }

            if ($review->cycle_sequence !== $cycleSequence) {
                throw ValidationException::withMessages([
                    'timesheet' => 'La revue des chevauchements est obsolète : un nouveau cycle de correction exige une nouvelle revue.',
                ]);
            }

            if ($review->fingerprint !== $overlap['fingerprint']) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Un segment a été modifié depuis la revue des chevauchements ; une nouvelle revue est obligatoire.',
                ]);
            }

            if ($review->decision->blocksValidation()) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Un chevauchement rejeté bloque la validation du relevé.',
                ]);
            }
        }
    }

    /**
     * @return array{
     *     pair_key: string,
     *     first_segment_id: int,
     *     second_segment_id: int,
     *     fingerprint: string
     * }|null
     */
    private function findOverlap(Timesheet $timesheet, string $pairKey): ?array
    {
        foreach ($this->overlapsFor($timesheet) as $overlap) {
            if ($overlap['pair_key'] === $pairKey) {
                return $overlap;
            }
        }

        return null;
    }

    private function fingerprint(TimesheetSegment $first, TimesheetSegment $second): string
    {
        $payload = [
            'version' => 1,
            'segments' => [
                $this->segmentSnapshot($first),
                $this->segmentSnapshot($second),
            ],
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function segmentSnapshot(TimesheetSegment $segment): array
    {
        $segment->loadMissing('timesheet');

        return [
            'id' => (int) $segment->getKey(),
            'timesheet_id' => (int) $segment->timesheet_id,
            'employee_id' => (int) $segment->timesheet->employee_id,
            'category' => $segment->category->value,
            'start_at' => $segment->start_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'end_at' => $segment->end_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'start_offset_minutes' => (int) $segment->start_offset_minutes,
            'end_offset_minutes' => (int) $segment->end_offset_minutes,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function orderedIds(int $first, int $second): array
    {
        return $first <= $second ? [$first, $second] : [$second, $first];
    }
}
