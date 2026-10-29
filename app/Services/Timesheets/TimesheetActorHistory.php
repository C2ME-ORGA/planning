<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture de l'historique d'audit d'un relevé pour la séparation des tâches.
 *
 * L'incrément 2B écrit `created`, `updated` et `submitted` lors de la
 * création d'un relevé, de l'édition de ses segments et de sa transmission.
 * Le contrôle, la validation et la réouverture restent hors périmètre : la
 * piste dépendra de l'émission exhaustive de leurs événements par les
 * incréments suivants.
 * Une piste d'audit incomplète ou non attribuable n'est jamais interprétée
 * comme la preuve qu'un utilisateur n'a pas participé : la validation est
 * alors refusée. Un événement sans acteur (console, job) ne prouve rien.
 *
 * La piste de complétude est bornée par cycle : la frontière est la dernière
 * action `timesheet.reopened` ou `timesheet.correction_resumed` du relevé,
 * identifiée par la séquence du journal (jamais par `occurred_at`). Les
 * événements de complétude doivent être postérieurs à cette frontière. Les
 * exclusions de propriété, de préparation et de contrôle restent globales,
 * tous cycles confondus.
 */
final class TimesheetActorHistory
{
    /**
     * @return array<int, string>
     */
    private function preparationActions(): array
    {
        return [
            AuditAction::TimesheetCreated->value,
            AuditAction::TimesheetUpdated->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function controlActions(): array
    {
        return [
            AuditAction::TimesheetReturnedForCorrection->value,
            AuditAction::TimesheetReadyForValidation->value,
        ];
    }

    public function isPreparedBy(User $user, Timesheet $timesheet): bool
    {
        return $this->hasAction($timesheet, $this->preparationActions(), $user);
    }

    public function hasControlled(User $user, Timesheet $timesheet): bool
    {
        return $this->hasAction($timesheet, $this->controlActions(), $user);
    }

    /**
     * Une piste est complète si la création, la transmission et, le cas
     * échéant, le contrôle satisfaisant sont audités **avec un acteur
     * identifiable**, et si les événements de complétude appartiennent au
     * cycle courant. Un événement `actor_id = null` ne suffit jamais, y
     * compris pour l'événement de frontière.
     */
    public function hasCompleteTrail(Timesheet $timesheet): bool
    {
        $boundary = $this->latestCycleBoundary($timesheet);

        if ($boundary instanceof AuditEvent && $boundary->actor_id === null) {
            return false;
        }

        $boundarySequence = $boundary?->sequence;

        if (! $this->hasAttributedAction($timesheet, AuditAction::TimesheetCreated->value)) {
            return false;
        }

        if (! $this->hasAttributedActionAfter(
            $timesheet,
            AuditAction::TimesheetSubmitted->value,
            $boundarySequence,
        )) {
            return false;
        }

        if ($timesheet->status === TimesheetStatus::PretePourValidation
            && ! $this->hasAttributedActionAfter(
                $timesheet,
                AuditAction::TimesheetReadyForValidation->value,
                $boundarySequence,
            )) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $actions
     */
    private function hasAction(Timesheet $timesheet, array $actions, User $user): bool
    {
        return AuditEvent::query()
            ->where('subject_type', Timesheet::class)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $user->getKey())
            ->whereIn('action', $actions)
            ->exists();
    }

    private function hasAttributedAction(Timesheet $timesheet, string $action): bool
    {
        return $this->baseQuery($timesheet)
            ->where('action', $action)
            ->whereNotNull('actor_id')
            ->exists();
    }

    private function hasAttributedActionAfter(Timesheet $timesheet, string $action, ?int $sequence): bool
    {
        return $this->baseQuery($timesheet)
            ->where('action', $action)
            ->whereNotNull('actor_id')
            ->when(
                $sequence !== null,
                static fn (Builder $query): Builder => $query->where('sequence', '>', $sequence),
            )
            ->exists();
    }

    private function latestCycleBoundary(Timesheet $timesheet): ?AuditEvent
    {
        return $this->baseQuery($timesheet)
            ->whereIn('action', [
                AuditAction::TimesheetReopened->value,
                AuditAction::TimesheetCorrectionResumed->value,
            ])
            ->orderByDesc('sequence')
            ->first();
    }

    /**
     * @return Builder<AuditEvent>
     */
    private function baseQuery(Timesheet $timesheet): Builder
    {
        return AuditEvent::query()
            ->where('subject_type', Timesheet::class)
            ->where('subject_id', (string) $timesheet->getKey());
    }
}
