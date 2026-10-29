<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\User;

/**
 * Lecture de l'historique d'audit d'un relevé pour la séparation des tâches.
 *
 * Aucun service de workflow n'écrit encore ces événements : la règle dépend de
 * l'émission exhaustive des actions `timesheet.*` par les incréments suivants.
 * Une piste d'audit incomplète ou non attribuable n'est jamais interprétée
 * comme la preuve qu'un utilisateur n'a pas participé : la validation est
 * alors refusée. Un événement sans acteur (console, job) ne prouve rien.
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
     * identifiable**. Un événement `actor_id = null` ne suffit jamais.
     */
    public function hasCompleteTrail(Timesheet $timesheet): bool
    {
        if (! $this->hasAttributedAction($timesheet, AuditAction::TimesheetCreated->value)) {
            return false;
        }

        if (! $this->hasAttributedAction($timesheet, AuditAction::TimesheetSubmitted->value)) {
            return false;
        }

        if ($timesheet->status === TimesheetStatus::PretePourValidation
            && ! $this->hasAttributedAction($timesheet, AuditAction::TimesheetReadyForValidation->value)) {
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
        return AuditEvent::query()
            ->where('subject_type', Timesheet::class)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('action', $action)
            ->whereNotNull('actor_id')
            ->exists();
    }
}
