<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetMinutePrecision;
use App\Support\TimesheetReason;
use App\Support\TimesheetWeekBounds;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
        private readonly TimesheetActorHistory $history,
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

    /**
     * Transmission directe à la validation d'un brouillon corrigé par le
     * Bureau/Direction.
     *
     * Le parcours remplace la transmission du salarié et le contrôle
     * intermédiaire. Il reste réservé au Bureau/Direction, exige un motif et
     * revalide les segments sous verrou avant toute écriture. L'acteur est
     * ensuite exclu de la validation finale d'un relevé dont il n'est pas
     * propriétaire.
     */
    public function sendToValidation(Timesheet $timesheet, string $reason, User $actor): Timesheet
    {
        return $this->transition(
            timesheet: $timesheet,
            actor: $actor,
            target: TimesheetStatus::PretePourValidation,
            ability: 'sendToValidation',
            action: AuditAction::TimesheetSentToValidation,
            reason: $reason,
            guard: function (Timesheet $locked) use ($actor): void {
                if (! $this->history->hasCorrectedInCurrentCycle($actor, $locked)) {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Une correction effective du Bureau/Direction est requise avant la transmission directe à la validation.',
                    ]);
                }

                $this->assertSegmentsTransmittable($locked);
            },
            changes: ['path' => 'bureau_correction'],
        );
    }

    private const REASON_PROBE = 'motif de contrôle';

    /**
     * @param  (callable(Timesheet): void)|null  $guard
     * @param  array<string, mixed>  $changes
     */
    private function transition(
        Timesheet $timesheet,
        User $actor,
        TimesheetStatus $target,
        string $ability,
        AuditAction $action,
        ?string $reason = null,
        ?callable $guard = null,
        array $changes = [],
    ): Timesheet {
        return DB::transaction(function () use ($timesheet, $actor, $target, $ability, $action, $reason, $guard, $changes): Timesheet {
            $locked = Timesheet::query()
                ->whereKey($timesheet->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actor->isActive()) {
                throw new AuthorizationException('Ce compte est inactif et ne peut pas contrôler un relevé.');
            }

            $reason = $this->authorizeReasonedAction($actor, $locked, $ability, $reason);

            if ($guard !== null) {
                $guard($locked);
            }

            $from = $locked->status;

            $locked->forceFill(['status' => $target->value])->save();

            $this->audit->record(
                $action,
                $locked,
                ['from' => $from->value, 'to' => $target->value] + $changes,
                $reason,
                $actor,
            );

            return $locked;
        });
    }

    private function assertSegmentsTransmittable(Timesheet $timesheet): void
    {
        $segments = $timesheet->segments()->get();

        if ($segments->isEmpty()) {
            throw ValidationException::withMessages([
                'timesheet' => 'Un relevé sans segment ne peut pas être transmis à la validation.',
            ]);
        }

        foreach ($segments as $segment) {
            $start = $segment->start_at;
            $end = $segment->end_at;

            if ($start === null || $end === null) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Un segment du relevé est incomplet.',
                ]);
            }

            TimesheetMinutePrecision::assert($start, $end, 'timesheet');
            TimesheetWeekBounds::assertContains($timesheet, $start, $end, 'timesheet');
        }
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
