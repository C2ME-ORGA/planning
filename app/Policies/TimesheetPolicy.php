<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetActorHistory;
use App\Services\Timesheets\TimesheetPerimeter;

/**
 * Autorisations des relevés d'heures.
 *
 * La policy ne déclenche aucune transition : elle vérifie les permissions, le
 * périmètre historique et les règles de séparation des tâches. Les services de
 * workflow des incréments suivants restent seuls responsables de l'écriture.
 */
class TimesheetPolicy
{
    public function __construct(
        private readonly TimesheetPerimeter $perimeter,
        private readonly TimesheetActorHistory $history,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::TimesheetOwn)
            || $actor->hasPermission(Permission::TimesheetControl)
            || $actor->hasPermission(Permission::TimesheetValidate);
    }

    public function view(User $actor, Timesheet $timesheet): bool
    {
        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return true;
        }

        if ($this->isOwner($actor, $timesheet)) {
            return $actor->hasPermission(Permission::TimesheetOwn);
        }

        return $this->isPerimeterController($actor, $timesheet);
    }

    public function create(User $actor, Employee $employee): bool
    {
        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return true;
        }

        return $actor->hasPermission(Permission::TimesheetOwn)
            && $employee->user_id === $actor->getKey();
    }

    public function update(User $actor, Timesheet $timesheet): bool
    {
        if (! $timesheet->status->isEditable()) {
            return false;
        }

        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return true;
        }

        return $actor->hasPermission(Permission::TimesheetOwn)
            && $this->isOwner($actor, $timesheet);
    }

    public function submit(User $actor, Timesheet $timesheet): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::AControler, null)) {
            return false;
        }

        return $actor->hasPermission(Permission::TimesheetOwn)
            && $this->isOwner($actor, $timesheet)
            && $timesheet->segments()->exists();
    }

    public function requestCorrection(User $actor, Timesheet $timesheet, ?string $reason = null): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::ACorriger, $reason)) {
            return false;
        }

        return $this->canControl($actor, $timesheet);
    }

    public function resumeCorrection(User $actor, Timesheet $timesheet): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::Brouillon, null)) {
            return false;
        }

        if ($actor->hasPermission(Permission::WorkflowCorrect)) {
            return true;
        }

        return $actor->hasPermission(Permission::TimesheetOwn)
            && $this->isOwner($actor, $timesheet);
    }

    public function markReadyForValidation(User $actor, Timesheet $timesheet, ?string $reason = null): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::PretePourValidation, $reason)) {
            return false;
        }

        return $this->canControl($actor, $timesheet);
    }

    public function validate(User $actor, Timesheet $timesheet): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::Validee, null)) {
            return false;
        }

        if (! $actor->hasPermission(Permission::TimesheetValidate)) {
            return false;
        }

        if ($this->isOwner($actor, $timesheet)) {
            return false;
        }

        if ($this->history->isPreparedBy($actor, $timesheet)) {
            return false;
        }

        if ($this->history->hasControlled($actor, $timesheet)) {
            return false;
        }

        return $this->history->hasCompleteTrail($timesheet);
    }

    public function reopen(User $actor, Timesheet $timesheet, ?string $reason = null): bool
    {
        if (! $this->transitionAllowed($timesheet, TimesheetStatus::Brouillon, $reason)) {
            return false;
        }

        return $actor->hasPermission(Permission::TimesheetValidate)
            && $actor->hasPermission(Permission::WorkflowReopen);
    }

    public function delete(User $actor, Timesheet $timesheet): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Timesheet $timesheet): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    private function transitionAllowed(
        Timesheet $timesheet,
        TimesheetStatus $target,
        ?string $reason,
    ): bool {
        if (! $timesheet->status->canTransitionTo($target)) {
            return false;
        }

        return ! $timesheet->status->requiresReasonTo($target)
            || $this->hasValidReason($reason);
    }

    private function canControl(User $actor, Timesheet $timesheet): bool
    {
        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return true;
        }

        return $this->isPerimeterController($actor, $timesheet);
    }

    private function isPerimeterController(User $actor, Timesheet $timesheet): bool
    {
        return $actor->hasPermission(Permission::TimesheetControl)
            && $this->perimeter->canControl($actor, $timesheet);
    }

    private function isOwner(User $actor, Timesheet $timesheet): bool
    {
        return $timesheet->employee?->user_id === $actor->getKey();
    }

    private function hasValidReason(?string $reason): bool
    {
        if ($reason === null) {
            return false;
        }

        $reason = trim($reason);

        return $reason !== '' && mb_strlen($reason) <= 500;
    }
}
