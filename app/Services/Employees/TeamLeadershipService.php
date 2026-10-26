<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamLeadership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Responsabilité principale d'une équipe.
 *
 * La responsabilité est datée et historisée. Elle exige, pendant toute sa
 * période, une période d'emploi qui la couvre, un compte applicatif actif et un
 * rôle `chef_equipe` ou `conducteur_travaux`. Une seule responsabilité
 * principale peut couvrir une date donnée pour une équipe.
 */
class TeamLeadershipService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmploymentPeriodService $periods,
        private readonly EmployeeMutationLock $lock,
    ) {}

    public function assign(
        Team $team,
        Employee $employee,
        string $startDate,
        ?string $endDate,
        User $actor,
        ?string $reason = null,
    ): TeamLeadership {
        $this->assertCanManage($actor);

        $start = DatePeriod::parse($startDate);
        $end = DatePeriod::parseNullable($endDate);
        DatePeriod::assertOrder($start, $end);

        return $this->lock->run($employee, function () use ($team, $employee, $start, $end, $actor, $reason): TeamLeadership {
            return DB::transaction(function () use ($team, $employee, $start, $end, $actor, $reason): TeamLeadership {
                /** @var Team $team */
                $team = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
                /** @var Employee $employee */
                $employee = Employee::query()->whereKey($employee->getKey())->firstOrFail();

                $this->assertEligible($employee, $start, $end);
                $this->assertNoOverlap($team, $start, $end);

                $leadership = TeamLeadership::query()->create([
                    'team_id' => $team->getKey(),
                    'employee_id' => $employee->getKey(),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end?->toDateString(),
                ]);

                $this->audit->record(
                    AuditAction::TeamLeaderAssigned,
                    $leadership,
                    [
                        'team_id' => $team->getKey(),
                        'employee_id' => $employee->getKey(),
                        'start_date' => $start->toDateString(),
                        'end_date' => $end?->toDateString(),
                    ],
                    $reason,
                    $actor,
                );

                return $leadership;
            });
        });
    }

    /**
     * Réaffecte explicitement la responsabilité principale à un autre salarié
     * éligible. L'ancienne responsabilité ouverte est clôturée la veille de la
     * date d'effet ; l'historique est conservé et audité.
     */
    public function reassign(
        Team $team,
        Employee $newLeader,
        string $effectiveDate,
        User $actor,
        ?string $reason = null,
    ): TeamLeadership {
        $this->assertCanManage($actor);

        $effective = DatePeriod::parse($effectiveDate);

        if ($effective->lt(CarbonImmutable::today())) {
            throw new RuntimeException('La date de réaffectation ne peut pas être antérieure à aujourd’hui.');
        }

        return $this->lock->run($newLeader, function () use ($team, $newLeader, $effective, $actor, $reason): TeamLeadership {
            return DB::transaction(function () use ($team, $newLeader, $effective, $actor, $reason): TeamLeadership {
                /** @var Team $team */
                $team = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
                /** @var Employee $newLeader */
                $newLeader = Employee::query()->whereKey($newLeader->getKey())->firstOrFail();

                $current = TeamLeadership::query()
                    ->where('team_id', $team->getKey())
                    ->whereNull('end_date')
                    ->whereNull('cancelled_at')
                    ->orderByDesc('start_date')
                    ->lockForUpdate()
                    ->first();

                if ($current instanceof TeamLeadership) {
                    if ($current->employee_id === $newLeader->getKey()) {
                        throw new RuntimeException('Ce salarié est déjà le responsable principal de cette équipe.');
                    }

                    if ($effective->lte($current->start_date)) {
                        throw new RuntimeException(
                            'La date de réaffectation doit être postérieure au début de la responsabilité actuelle.',
                        );
                    }

                    $closingDate = $effective->subDay();
                    $current->forceFill(['end_date' => $closingDate->toDateString()])->save();

                    $this->audit->record(
                        AuditAction::TeamLeaderClosed,
                        $current,
                        [
                            'team_id' => $team->getKey(),
                            'employee_id' => $current->employee_id,
                            'end_date' => $closingDate->toDateString(),
                        ],
                        $reason ?? 'Clôture liée à une réaffectation.',
                        $actor,
                    );
                }

                $this->assertEligible($newLeader, $effective, null);
                $this->assertNoOverlap($team, $effective, null);

                $leadership = TeamLeadership::query()->create([
                    'team_id' => $team->getKey(),
                    'employee_id' => $newLeader->getKey(),
                    'start_date' => $effective->toDateString(),
                    'end_date' => null,
                ]);

                $this->audit->record(
                    AuditAction::TeamLeaderReassigned,
                    $leadership,
                    [
                        'team_id' => $team->getKey(),
                        'previous_employee_id' => $current?->employee_id,
                        'employee_id' => $newLeader->getKey(),
                        'effective_date' => $effective->toDateString(),
                    ],
                    $reason,
                    $actor,
                );

                return $leadership;
            });
        });
    }

    public function close(
        TeamLeadership $leadership,
        string $endDate,
        User $actor,
        ?string $reason = null,
    ): TeamLeadership {
        $this->assertCanManage($actor);

        $end = DatePeriod::parse($endDate);

        if ($leadership->cancelled_at !== null) {
            throw new RuntimeException('Cette responsabilité a été annulée : elle ne peut pas être clôturée.');
        }

        if ($leadership->end_date !== null) {
            throw new RuntimeException('Cette responsabilité est déjà clôturée.');
        }

        if ($end->lt($leadership->start_date)) {
            throw new RuntimeException('La date de fin ne peut pas précéder la date de début.');
        }

        return DB::transaction(function () use ($leadership, $end, $actor, $reason): TeamLeadership {
            /** @var TeamLeadership $leadership */
            $leadership = TeamLeadership::query()
                ->whereKey($leadership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($leadership->cancelled_at !== null) {
                throw new RuntimeException('Cette responsabilité a été annulée : elle ne peut pas être clôturée.');
            }

            if ($leadership->end_date !== null) {
                throw new RuntimeException('Cette responsabilité est déjà clôturée.');
            }

            $this->periods->assertCovered(
                $leadership->employee,
                $leadership->start_date->toDateString(),
                $end->toDateString(),
            );

            $leadership->forceFill(['end_date' => $end->toDateString()])->save();

            $this->audit->record(
                AuditAction::TeamLeaderClosed,
                $leadership,
                [
                    'team_id' => $leadership->team_id,
                    'employee_id' => $leadership->employee_id,
                    'end_date' => $end->toDateString(),
                ],
                $reason,
                $actor,
            );

            return $leadership;
        });
    }

    /**
     * Annule une responsabilité future avant son début, sans supprimer
     * l'historique. Utilisé notamment avant une sortie ou un changement de rôle.
     */
    public function cancel(TeamLeadership $leadership, string $reason, User $actor): TeamLeadership
    {
        $this->assertCanManage($actor);
        $this->assertReason($reason);

        if ($leadership->cancelled_at !== null) {
            throw new RuntimeException('Cette responsabilité est déjà annulée.');
        }

        if ($leadership->start_date->lte(now()->startOfDay())) {
            throw new RuntimeException(
                'Une responsabilité déjà commencée ne peut pas être annulée. Clôturez-la avec une date de fin.',
            );
        }

        return DB::transaction(function () use ($leadership, $reason, $actor): TeamLeadership {
            /** @var TeamLeadership $leadership */
            $leadership = TeamLeadership::query()
                ->whereKey($leadership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($leadership->cancelled_at !== null) {
                throw new RuntimeException('Cette responsabilité a déjà été annulée.');
            }

            $leadership->forceFill([
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record(
                AuditAction::TeamLeaderCancelled,
                $leadership,
                [
                    'team_id' => $leadership->team_id,
                    'employee_id' => $leadership->employee_id,
                    'start_date' => $leadership->start_date->toDateString(),
                ],
                $reason,
                $actor,
            );

            return $leadership;
        });
    }

    /**
     * Responsabilités non terminées à la date indiquée, y compris celles qui
     * commencent plus tard. Elles empêchent un retrait de rôle ou de compte.
     *
     * @return Collection<int, TeamLeadership>
     */
    public function activeResponsibilitiesFor(User $user, ?CarbonInterface $at = null): Collection
    {
        $employee = Employee::query()->where('user_id', $user->getKey())->first();

        if (! $employee instanceof Employee) {
            return new Collection;
        }

        return $employee->responsibilitiesNotEndedAt($at);
    }

    public function assertRoleRemovalAllowed(User $user, Role $newRole): void
    {
        if (in_array($newRole, [Role::ChefEquipe, Role::Conducteur], true)) {
            return;
        }

        $responsibilities = $this->activeResponsibilitiesFor($user);

        if ($responsibilities->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'Ce compte est encore responsable principal d’une équipe ('
            .$this->summarize($responsibilities)
            .'). Réaffectez la responsabilité ou clôturez-la explicitement avant de retirer le rôle.',
        );
    }

    public function assertDeactivationAllowed(User $user): void
    {
        $responsibilities = $this->activeResponsibilitiesFor($user);

        if ($responsibilities->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'Ce compte est encore responsable principal d’une équipe ('
            .$this->summarize($responsibilities)
            .'). Réaffectez la responsabilité ou clôturez-la explicitement avant de désactiver le compte.',
        );
    }

    /**
     * Un changement de compte (liaison ou déliaison) ne doit pas rendre une
     * responsabilité active incompatible avec l'exigence de compte actif.
     */
    public function assertAccountChangeAllowed(Employee $employee, ?User $newUser): void
    {
        $responsibilities = $employee->responsibilitiesNotEndedAt();

        if ($responsibilities->isEmpty()) {
            return;
        }

        if (! $newUser instanceof User) {
            throw new RuntimeException(
                'Ce salarié est responsable principal d’une équipe ('
                .$this->summarize($responsibilities)
                .'). Réaffectez ou clôturez la responsabilité avant de délier son compte.',
            );
        }

        $this->assertUserEligible($newUser);
    }

    /**
     * @return array<int, string>
     */
    public function exitBlockers(Employee $employee, string $exitDate): array
    {
        $exit = DatePeriod::parse($exitDate);
        $blockers = [];

        $assignments = $employee->teamAssignments()
            ->with('team')
            ->whereNull('cancelled_at')
            ->where(static function ($query) use ($exit): void {
                $query->whereNull('end_date')->orWhere('end_date', '>', $exit->toDateString());
            })
            ->orderBy('start_date')
            ->get();

        foreach ($assignments as $assignment) {
            $blockers[] = sprintf(
                '%s — équipe « %s » : %s.',
                $assignment->kind->label(),
                $assignment->team->name,
                $this->periodLabel($assignment->start_date, $assignment->end_date),
            );
        }

        $leaderships = $employee->leaderships()
            ->with('team')
            ->whereNull('cancelled_at')
            ->where(static function ($query) use ($exit): void {
                $query->whereNull('end_date')->orWhere('end_date', '>', $exit->toDateString());
            })
            ->orderBy('start_date')
            ->get();

        foreach ($leaderships as $leadership) {
            $blockers[] = sprintf(
                'Responsabilité principale — équipe « %s » : %s.',
                $leadership->team->name,
                $this->periodLabel($leadership->start_date, $leadership->end_date),
            );
        }

        return $blockers;
    }

    private function assertEligible(Employee $employee, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $this->periods->assertCovered($employee, $start->toDateString(), $end?->toDateString());

        $user = $employee->user;

        if (! $user instanceof User) {
            throw new RuntimeException('Un responsable principal doit disposer d’un compte applicatif.');
        }

        $this->assertUserEligible($user);
    }

    private function assertUserEligible(User $user): void
    {
        if (! $user->isActive()) {
            throw new RuntimeException('Un responsable principal doit disposer d’un compte applicatif actif.');
        }

        if (! in_array($user->role, [Role::ChefEquipe, Role::Conducteur], true)) {
            throw new RuntimeException(
                'Un responsable principal doit avoir le rôle Chef d’équipe ou Conducteur de travaux.',
            );
        }
    }

    private function assertNoOverlap(Team $team, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $overlap = TeamLeadership::query()
            ->where('team_id', $team->getKey())
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', DatePeriod::endOrMax($end))
            ->where(static function ($query) use ($start): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString());
            })
            ->exists();

        if ($overlap) {
            throw new RuntimeException(
                'Cette équipe possède déjà un responsable principal sur cette période. '
                .'Clôturez ou réaffectez la responsabilité existante.',
            );
        }
    }

    /**
     * @param  Collection<int, TeamLeadership>  $responsibilities
     */
    private function summarize(Collection $responsibilities): string
    {
        return $responsibilities
            ->map(fn (TeamLeadership $leadership): string => sprintf(
                'équipe « %s » %s',
                $leadership->team->name,
                $this->periodLabel($leadership->start_date, $leadership->end_date),
            ))
            ->implode(' ; ');
    }

    private function periodLabel(CarbonInterface $start, ?CarbonInterface $end): string
    {
        return $end === null
            ? 'depuis le '.$start->format('d/m/Y').' sans date de fin'
            : 'du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
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
            throw new RuntimeException('Ce compte n’est pas autorisé à gérer les responsabilités d’équipe.');
        }
    }
}
