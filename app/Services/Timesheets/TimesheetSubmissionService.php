<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetMinutePrecision;
use App\Support\TimesheetWeekBounds;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Transmission d'un relevé vers l'état « À contrôler ».
 *
 * La transmission est réservée au salarié propriétaire, dont le compte doit
 * être actif et lié à sa fiche salarié. Le Bureau et les responsables ne
 * transmettent jamais à la place du salarié.
 *
 * L'opération est sérialisée par le verrou de la ligne `timesheets` concernée :
 * les écritures de segments prennent le même verrou, donc aucun segment ne
 * peut être ajouté, modifié ou supprimé après une transmission réussie, et le
 * dernier segment ne peut pas disparaître entre la vérification et le
 * changement de statut.
 *
 * Elle est idempotente par état : un relevé déjà « À contrôler » est retourné
 * tel quel, sans nouvel événement d'audit. Aucune clé d'idempotence réseau
 * n'est introduite (Lot 10).
 */
class TimesheetSubmissionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkedTimeCalculator $calculator,
        private readonly TimesheetOverlapDetector $overlaps,
    ) {}

    public function submit(Timesheet $timesheet, User $actor): Timesheet
    {
        if (! $actor->isActive()) {
            throw new AuthorizationException('Ce compte ne peut pas transmettre un relevé.');
        }

        return DB::transaction(function () use ($timesheet, $actor): Timesheet {
            $locked = Timesheet::query()
                ->whereKey($timesheet->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnedBy($locked, $actor);

            if ($locked->status === TimesheetStatus::AControler) {
                return $locked;
            }

            $segments = $locked->segments()->get();

            if ($segments->isEmpty()) {
                throw ValidationException::withMessages([
                    'timesheet' => 'Un relevé sans segment ne peut pas être transmis.',
                ]);
            }

            Gate::forUser($actor)->authorize('submit', $locked);

            // Les segments peuvent avoir été écrits hors du service (import,
            // donnée historique) : la transmission revalide la précision à la
            // minute et les bornes de la semaine sans jamais modifier les
            // instants reçus.
            foreach ($segments as $segment) {
                $start = $segment->start_at;
                $end = $segment->end_at;

                if ($start === null || $end === null) {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Un segment du relevé est incomplet.',
                    ]);
                }

                TimesheetMinutePrecision::assert($start, $end, 'timesheet');

                TimesheetWeekBounds::assertContains($locked, $start, $end, 'timesheet');
            }

            $warnings = $this->warningsFor($segments);

            $locked->forceFill(['status' => TimesheetStatus::AControler->value])->save();

            $this->audit->record(
                AuditAction::TimesheetSubmitted,
                $locked,
                [
                    'from' => TimesheetStatus::Brouillon->value,
                    'to' => TimesheetStatus::AControler->value,
                    'segments_count' => $segments->count(),
                ],
                null,
                $actor,
                ['warnings' => $warnings],
            );

            return $locked;
        });
    }

    private function assertOwnedBy(Timesheet $timesheet, User $actor): void
    {
        $timesheet->loadMissing('employee');

        if (! $actor->hasPermission(Permission::TimesheetOwn)
            || $timesheet->employee?->user_id !== $actor->getKey()) {
            throw new AuthorizationException('Ce relevé ne peut pas être transmis par ce compte.');
        }
    }

    /**
     * @param  Collection<int, TimesheetSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function warningsFor(Collection $segments): array
    {
        $warnings = [];

        foreach ($segments as $segment) {
            foreach ($this->overlaps->warningsForSegment($segment) as $warning) {
                $warnings[$this->warningKey($warning)] = $warning;
            }

            $start = $segment->start_at;
            $end = $segment->end_at;

            if ($start === null || $end === null) {
                continue;
            }

            $minutes = $this->calculator->minutesBetween($start, $end);

            foreach ($this->calculator->warningsForDuration($minutes) as $warning) {
                $warning['segment_id'] = (int) $segment->getKey();
                $warnings[$this->warningKey($warning)] = $warning;
            }
        }

        return array_values($warnings);
    }

    /**
     * @param  array<string, mixed>  $warning
     */
    private function warningKey(array $warning): string
    {
        return implode('|', [
            (string) ($warning['type'] ?? ''),
            (string) ($warning['segment_id'] ?? ''),
            (string) ($warning['timesheet_id'] ?? ''),
            (string) ($warning['start_at'] ?? ''),
            (string) ($warning['end_at'] ?? ''),
            (string) ($warning['minutes'] ?? ''),
        ]);
    }
}
