<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\TeamAssignmentKind;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Affectations principales et temporaires historisées.
 *
 * Une affectation temporaire est un enregistrement distinct : elle ne modifie
 * jamais l'équipe principale. Aucune affectation n'est supprimée ; une
 * affectation se clôt explicitement par une date de fin.
 */
class TeamAssignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmploymentPeriodService $periods,
        private readonly EmployeeMutationLock $lock,
    ) {}

    public function assign(
        Employee $employee,
        Team $team,
        TeamAssignmentKind $kind,
        string $startDate,
        ?string $endDate,
        User $actor,
    ): TeamAssignment {
        $this->assertCanManage($actor);

        $start = DatePeriod::parse($startDate);
        $end = DatePeriod::parseNullable($endDate);
        DatePeriod::assertOrder($start, $end);

        return $this->lock->run($employee, function () use ($employee, $team, $kind, $start, $end, $actor): TeamAssignment {
            return DB::transaction(function () use ($employee, $team, $kind, $start, $end, $actor): TeamAssignment {
                $employee = Employee::query()->whereKey($employee->getKey())->firstOrFail();

                $this->periods->assertCovered($employee, $start->toDateString(), $end?->toDateString());
                $this->assertNoOverlap($employee, $kind, $start, $end);

                $assignment = TeamAssignment::query()->create([
                    'team_id' => $team->getKey(),
                    'employee_id' => $employee->getKey(),
                    'kind' => $kind,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end?->toDateString(),
                ]);

                $this->audit->record(
                    AuditAction::TeamAssignmentCreated,
                    $assignment,
                    [
                        'employee_id' => $employee->getKey(),
                        'team_id' => $team->getKey(),
                        'kind' => $kind->value,
                        'start_date' => $start->toDateString(),
                        'end_date' => $end?->toDateString(),
                    ],
                    null,
                    $actor,
                );

                return $assignment;
            });
        });
    }

    public function close(TeamAssignment $assignment, string $endDate, User $actor): TeamAssignment
    {
        $this->assertCanManage($actor);

        $end = DatePeriod::parse($endDate);

        if ($assignment->cancelled_at !== null) {
            throw new RuntimeException('Cette affectation a été annulée : elle ne peut pas être clôturée.');
        }

        if ($assignment->end_date !== null) {
            throw new RuntimeException('Cette affectation est déjà clôturée.');
        }

        if ($end->lt($assignment->start_date)) {
            throw new RuntimeException('La date de fin ne peut pas précéder la date de début.');
        }

        return $this->lock->run($assignment->employee_id, function () use ($assignment, $end, $actor): TeamAssignment {
            return DB::transaction(function () use ($assignment, $end, $actor): TeamAssignment {
                /** @var TeamAssignment $assignment */
                $assignment = TeamAssignment::query()
                    ->whereKey($assignment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($assignment->cancelled_at !== null) {
                    throw new RuntimeException('Cette affectation a été annulée : elle ne peut pas être clôturée.');
                }

                if ($assignment->end_date !== null) {
                    throw new RuntimeException('Cette affectation est déjà clôturée.');
                }

                $employee = $assignment->employee;
                $this->periods->assertCovered(
                    $employee,
                    $assignment->start_date->toDateString(),
                    $end->toDateString(),
                );

                $assignment->forceFill(['end_date' => $end->toDateString()])->save();

                $this->audit->record(
                    AuditAction::TeamAssignmentClosed,
                    $assignment,
                    [
                        'employee_id' => $assignment->employee_id,
                        'team_id' => $assignment->team_id,
                        'end_date' => $end->toDateString(),
                    ],
                    null,
                    $actor,
                );

                return $assignment;
            });
        });
    }

    /**
     * Annule une affectation future avant son début. L'enregistrement reste
     * conservé et audité ; aucune ligne historique n'est supprimée.
     */
    public function cancel(TeamAssignment $assignment, string $reason, User $actor): TeamAssignment
    {
        $this->assertCanManage($actor);
        $this->assertReason($reason);

        if ($assignment->cancelled_at !== null) {
            throw new RuntimeException('Cette affectation est déjà annulée.');
        }

        if ($assignment->start_date->lte(now()->startOfDay())) {
            throw new RuntimeException(
                'Une affectation déjà commencée ne peut pas être annulée. Clôturez-la avec une date de fin.',
            );
        }

        return $this->lock->run($assignment->employee_id, function () use ($assignment, $reason, $actor): TeamAssignment {
            return DB::transaction(function () use ($assignment, $reason, $actor): TeamAssignment {
                /** @var TeamAssignment $assignment */
                $assignment = TeamAssignment::query()
                    ->whereKey($assignment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($assignment->cancelled_at !== null) {
                    throw new RuntimeException('Cette affectation a déjà été annulée.');
                }

                $assignment->forceFill([
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ])->save();

                $this->audit->record(
                    AuditAction::TeamAssignmentCancelled,
                    $assignment,
                    [
                        'employee_id' => $assignment->employee_id,
                        'team_id' => $assignment->team_id,
                        'start_date' => $assignment->start_date->toDateString(),
                    ],
                    $reason,
                    $actor,
                );

                return $assignment;
            });
        });
    }

    private function assertNoOverlap(
        Employee $employee,
        TeamAssignmentKind $kind,
        CarbonImmutable $start,
        ?CarbonImmutable $end,
    ): void {
        $overlap = TeamAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('kind', $kind->value)
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', DatePeriod::endOrMax($end))
            ->where(static function ($query) use ($start): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString());
            })
            ->exists();

        if ($overlap) {
            throw new RuntimeException(
                $kind === TeamAssignmentKind::Primary
                    ? 'Ce salarié possède déjà une équipe principale sur cette période.'
                    : 'Ce salarié possède déjà une affectation temporaire sur cette période.',
            );
        }
    }

    private function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));

        if ($length < 10) {
            throw new RuntimeException('Un motif d’au moins 10 caractères est requis.');
        }

        if ($length > 500) {
            throw new RuntimeException('Le motif ne peut pas dépasser 500 caractères.');
        }
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::TeamsManage)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à gérer les affectations d’équipe.');
        }
    }
}
