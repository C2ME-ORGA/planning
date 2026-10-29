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
 * Validation finale et réouverture d'un relevé.
 *
 * La validation verrouille le relevé parent avant de lire l'historique des
 * acteurs, réautorise l'acteur sur l'instance rechargée et laisse la policy
 * vérifier la séparation des tâches et la complétude du cycle courant. Le
 * statut et l'événement `timesheet.validated` sont écrits dans la même
 * transaction.
 *
 * La réouverture exige un motif normalisé une seule fois, utilisé à la fois
 * pour l'autorisation et pour l'audit. Une requête répétée ne produit ni
 * transition ni événement : la matrice d'états refuse la seconde demande.
 */
class TimesheetValidationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function validate(Timesheet $timesheet, User $actor): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $actor): Timesheet {
            $locked = $this->lockTimesheet($timesheet);

            // L'historique est lu par la policy après acquisition du verrou.
            Gate::forUser($actor)->authorize('validate', $locked);

            $from = $locked->status;

            $locked->forceFill(['status' => TimesheetStatus::Validee->value])->save();

            $this->audit->record(
                AuditAction::TimesheetValidated,
                $locked,
                ['from' => $from->value, 'to' => TimesheetStatus::Validee->value],
                null,
                $actor,
            );

            return $locked;
        });
    }

    public function reopen(Timesheet $timesheet, string $reason, User $actor): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $reason, $actor): Timesheet {
            $locked = $this->lockTimesheet($timesheet);

            $reason = $this->authorizeReopen($actor, $locked, $reason);

            $from = $locked->status;

            $locked->forceFill(['status' => TimesheetStatus::Brouillon->value])->save();

            $this->audit->record(
                AuditAction::TimesheetReopened,
                $locked,
                ['from' => $from->value, 'to' => TimesheetStatus::Brouillon->value],
                $reason,
                $actor,
            );

            return $locked;
        });
    }

    private const REASON_PROBE = 'motif';

    /**
     * Autorise la réouverture avant de valider le contenu du motif : un acteur
     * non habilité reçoit un refus d'autorisation, pas une erreur de validation.
     * Le motif de sonde n'est ni journalisé ni enregistré ; la valeur réelle est
     * normalisée puis réautorisée.
     */
    private function authorizeReopen(User $actor, Timesheet $locked, string $reason): string
    {
        Gate::forUser($actor)->authorize('reopen', [$locked, self::REASON_PROBE]);

        $normalized = TimesheetReason::required($reason);

        Gate::forUser($actor)->authorize('reopen', [$locked, $normalized]);

        return $normalized;
    }

    private function lockTimesheet(Timesheet $timesheet): Timesheet
    {
        return Timesheet::query()
            ->whereKey($timesheet->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
