<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Chronologie lisible d'un relevé, construite exclusivement depuis le journal
 * d'audit append-only.
 *
 * Aucun événement n'est inventé : les informations absentes (acteur, motif,
 * décision) restent absentes. La lecture globale est réservée aux callers
 * autorisés ; cette classe ne remplace pas les policies.
 */
final class TimesheetHistory
{
    /**
     * @return array<int, string>
     */
    public static function actions(): array
    {
        return [
            AuditAction::TimesheetCreated->value,
            AuditAction::TimesheetUpdated->value,
            AuditAction::TimesheetSubmitted->value,
            AuditAction::TimesheetSentToValidation->value,
            AuditAction::TimesheetReturnedForCorrection->value,
            AuditAction::TimesheetCorrectionResumed->value,
            AuditAction::TimesheetReadyForValidation->value,
            AuditAction::TimesheetValidated->value,
            AuditAction::TimesheetReopened->value,
            AuditAction::TimesheetOverlapReviewAccepted->value,
            AuditAction::TimesheetOverlapReviewRejected->value,
            AuditAction::SelfApprovalRecorded->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        $options = [];

        foreach (self::actions() as $action) {
            $case = AuditAction::tryFrom($action);

            if ($case instanceof AuditAction) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }

    /**
     * @return Builder<AuditEvent>
     */
    public function globalQuery(): Builder
    {
        return AuditEvent::query()
            ->where('subject_type', Timesheet::class)
            ->whereIn('action', self::actions())
            ->with('actor');
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    public function eventsFor(Timesheet $timesheet): Collection
    {
        return $this->globalQuery()
            ->where('subject_id', (string) $timesheet->getKey())
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return array<int, array{
     *     sequence: int,
     *     occurred_at: CarbonImmutable,
     *     actor: string|null,
     *     actor_role: string|null,
     *     action: string,
     *     label: string,
     *     reason: string|null,
     *     details: array<int, string>,
     *     subject_id: string|null
     * }>
     */
    public function presentMany(Collection $events): array
    {
        $entries = [];

        foreach ($events as $event) {
            $entries[] = $this->present($event);
        }

        return $entries;
    }

    /**
     * @return array{
     *     sequence: int,
     *     occurred_at: CarbonImmutable,
     *     actor: string|null,
     *     actor_role: string|null,
     *     action: string,
     *     label: string,
     *     reason: string|null,
     *     details: array<int, string>,
     *     subject_id: string|null
     * }
     */
    public function present(AuditEvent $event): array
    {
        $event->loadMissing('actor');

        return [
            'sequence' => $event->sequence,
            'occurred_at' => BusinessTime::toLocal($event->occurred_at),
            'actor' => $event->actor?->name,
            'actor_role' => $this->historicalRole($event),
            'action' => $event->action->value,
            'label' => $this->label($event),
            'reason' => $event->reason,
            'details' => $this->details($event),
            'subject_id' => $event->subject_id,
        ];
    }

    /**
     * Rôle enregistré au moment de l'événement.
     *
     * `audit_events.actor_role` est la seule source historique fiable. Si la
     * valeur est absente ou invalide, on retourne `null` plutôt que d'afficher
     * le rôle actuel de l'acteur, qui pourrait avoir changé depuis l'action.
     */
    private function historicalRole(AuditEvent $event): ?string
    {
        $actorRole = $event->getAttribute('actor_role');

        if (! is_string($actorRole) || $actorRole === '') {
            return null;
        }

        return Role::tryFrom($actorRole)?->label();
    }

    /**
     * Dernière demande de correction enregistrée pour le relevé.
     *
     * @param  Collection<int, AuditEvent>  $events
     * @return array{reason: string|null, actor: string|null, occurred_at: CarbonImmutable}|null
     */
    public function latestCorrectionRequest(Collection $events): ?array
    {
        $latest = $events
            ->filter(static fn (AuditEvent $event): bool => $event->action === AuditAction::TimesheetReturnedForCorrection)
            ->sortByDesc('sequence')
            ->first();

        if (! $latest instanceof AuditEvent) {
            return null;
        }

        $latest->loadMissing('actor');

        return [
            'reason' => $latest->reason,
            'actor' => $latest->actor?->name,
            'occurred_at' => BusinessTime::toLocal($latest->occurred_at),
        ];
    }

    private function label(AuditEvent $event): string
    {
        if ($event->action === AuditAction::TimesheetUpdated) {
            $operation = $event->changes['operation'] ?? null;

            return match ($operation) {
                'segment_added' => 'Segment ajouté',
                'segment_updated' => 'Segment modifié',
                'segment_deleted' => 'Segment supprimé',
                default => $event->action->label(),
            };
        }

        if ($event->action === AuditAction::SelfApprovalRecorded) {
            return 'Auto-validation motivée';
        }

        return $event->action->label();
    }

    /**
     * @return array<int, string>
     */
    private function details(AuditEvent $event): array
    {
        $changes = $event->changes ?? [];
        $details = [];

        $from = $this->statusLabel($changes['from'] ?? null);
        $to = $this->statusLabel($changes['to'] ?? null);

        if ($from !== null && $to !== null) {
            $details[] = sprintf('Statut : %s → %s', $from, $to);
        } elseif ($to !== null) {
            $details[] = sprintf('Statut : %s', $to);
        } else {
            $status = $this->statusLabel($changes['status'] ?? null);

            if ($status !== null) {
                $details[] = sprintf('Statut : %s', $status);
            }
        }

        $periodStart = $this->formatDate($changes['period_start'] ?? null);
        $periodEnd = $this->formatDate($changes['period_end'] ?? null);

        if ($periodStart !== null || $periodEnd !== null) {
            $details[] = sprintf(
                'Période : %s → %s',
                $periodStart ?? '—',
                $periodEnd ?? '—',
            );
        }

        $category = $this->categoryLabel($changes['category'] ?? null);
        $start = $this->formatInstant($changes['start_at'] ?? null);
        $end = $this->formatInstant($changes['end_at'] ?? null);

        if ($category !== null) {
            $details[] = sprintf('Catégorie : %s', $category);
        }

        if ($start !== null || $end !== null) {
            $details[] = sprintf(
                'Horaire : %s → %s',
                $start ?? '—',
                $end ?? '—',
            );
        }

        $this->appendFieldChange($details, $changes, 'category', 'Catégorie', 'categoryLabel');
        $this->appendFieldChange($details, $changes, 'start_at', 'Début', 'formatInstant');
        $this->appendFieldChange($details, $changes, 'end_at', 'Fin', 'formatInstant');

        if (isset($changes['segments_count'])) {
            $details[] = sprintf('Segments transmis : %d', (int) $changes['segments_count']);
        }

        if (($changes['path'] ?? null) === 'bureau_correction') {
            $details[] = 'Transmission directe par le Bureau/Direction';
        }

        $warnings = $event->context['warnings'] ?? null;

        if (is_array($warnings) && $warnings !== []) {
            $details[] = sprintf('Avertissements non bloquants : %d', count($warnings));
        }

        $firstSegment = $changes['first_segment_id'] ?? null;
        $secondSegment = $changes['second_segment_id'] ?? null;

        if ($firstSegment !== null && $secondSegment !== null) {
            $details[] = sprintf(
                'Segments concernés : n°%d et n°%d',
                (int) $firstSegment,
                (int) $secondSegment,
            );
        }

        $decision = $this->decisionLabel($changes['decision'] ?? null);

        if ($decision !== null) {
            $details[] = sprintf('Décision : %s', $decision);
        }

        return $details;
    }

    /**
     * @param  array<int, string>  $details
     * @param  array<string, mixed>  $changes
     */
    private function appendFieldChange(
        array &$details,
        array $changes,
        string $field,
        string $label,
        string $formatter,
    ): void {
        $before = $changes['before'][$field] ?? null;
        $after = $changes['after'][$field] ?? null;

        if ($before === null && $after === null) {
            return;
        }

        $format = fn (mixed $value): string => match ($formatter) {
            'categoryLabel' => $this->categoryLabel($value) ?? '—',
            default => $this->formatInstant($value) ?? '—',
        };

        $details[] = sprintf('%s : %s → %s', $label, $format($before), $format($after));
    }

    private function statusLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return TimesheetStatus::tryFrom($value)?->label();
    }

    private function categoryLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return TimeCategory::tryFrom($value)?->label();
    }

    private function decisionLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return OverlapReviewDecision::tryFrom($value)?->label();
    }

    private function formatInstant(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return BusinessTime::toLocal(CarbonImmutable::parse($value))->format('d/m/Y H:i');
        } catch (Throwable) {
            return null;
        }
    }

    private function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }
}
