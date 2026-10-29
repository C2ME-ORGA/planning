<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Contrôle d'un relevé : retour en correction, reprise et préparation à la
 * validation.
 *
 * Chaque transition verrouille le relevé parent, réautorise l'acteur sur
 * l'instance rechargée, vérifie la matrice d'états et le motif éventuel via la
 * policy, puis écrit le statut et l'événement d'audit dans la même transaction.
 *
 * Une requête répétée ne produit ni transition ni événement : la matrice
 * d'états refuse la seconde demande. Seule la transmission est idempotente par
 * état (voir `TimesheetSubmissionService`).
 */
class TimesheetControlService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function requestCorrection(Timesheet $timesheet, string $reason, User $actor): Timesheet
    {
        return $this->transition(
            timesheet: $timesheet,
            actor: $actor,
            target: TimesheetStatus::ACorriger,
            ability: 'requestCorrection',
            action: AuditAction::TimesheetReturnedForCorrection,
            reason: $reason,
        );
    }

    public function resumeCorrection(Timesheet $timesheet, User $actor): Timesheet
    {
        return $this->transition(
            timesheet: $timesheet,
            actor: $actor,
            target: TimesheetStatus::Brouillon,
            ability: 'resumeCorrection',
            action: AuditAction::TimesheetCorrectionResumed,
        );
    }

    public function markReadyForValidation(Timesheet $timesheet, string $reason, User $actor): Timesheet
    {
        return $this->transition(
            timesheet: $timesheet,
            actor: $actor,
            target: TimesheetStatus::PretePourValidation,
            ability: 'markReadyForValidation',
            action: AuditAction::TimesheetReadyForValidation,
            reason: $reason,
        );
    }

    private const REASON_PROBE = 'motif';

    private function transition(
        Timesheet $timesheet,
        User $actor,
        TimesheetStatus $target,
        string $ability,
        AuditAction $action,
        ?string $reason = null,
    ): Timesheet {
        return DB::transaction(function () use ($timesheet, $actor, $target, $ability, $action, $reason): Timesheet {
            $locked = Timesheet::query()
                ->whereKey($timesheet->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $reason = $this->authorizeReasonedAction($actor, $locked, $ability, $reason);

            $from = $locked->status;

            $locked->forceFill(['status' => $target->value])->save();

            $this->audit->record(
                $action,
                $locked,
                ['from' => $from->value, 'to' => $target->value],
                $reason,
                $actor,
            );

            return $locked;
        });
    }

    /**
     * Autorise l'action avant de valider le contenu du motif : un acteur non
     * autorisé doit recevoir un refus d'autorisation, jamais une erreur de
     * validation métier. Le motif de sonde n'est ni journalisé ni enregistré ;
     * la valeur réelle est normalisée puis réautorisée avant la transition.
     */
    private function authorizeReasonedAction(
        User $actor,
        Timesheet $locked,
        string $ability,
        ?string $reason,
    ): ?string {
        if ($reason === null) {
            Gate::forUser($actor)->authorize($ability, $locked);

            return null;
        }

        Gate::forUser($actor)->authorize($ability, [$locked, self::REASON_PROBE]);

        $normalized = TimesheetReason::required($reason);

        Gate::forUser($actor)->authorize($ability, [$locked, $normalized]);

        return $normalized;
    }
}
