<?php

namespace App\Services\Planning;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\PlanningHalfDay;
use App\Models\Employee;
use App\Models\PlanningAssignment;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Planning des chantiers par demi-journée.
 *
 * Une affectation référence explicitement un chantier, une équipe, une date
 * civile et une demi-journée. Les membres de l'équipe sont déduits à la date via
 * `PlanningRoster` ; les exclusions sont stockées séparément et ne modifient
 * jamais les affectations d'équipe historisées.
 *
 * Les chevauchements sont signalés dans le résultat et dans l'audit, sans
 * bloquer l'enregistrement. Aucune affectation existante n'est déplacée,
 * remplacée ou supprimée automatiquement.
 */
class PlanningAssignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlanningRoster $roster,
    ) {}

    /**
     * @param  array<int, mixed>  $excludedEmployeeIds
     */
    public function create(
        Site $site,
        Team $team,
        string $date,
        PlanningHalfDay $halfDay,
        array $excludedEmployeeIds,
        User $actor,
    ): PlanningAssignment {
        $this->assertCanManage($actor, $site, $team);

        $workDate = $this->parseWorkDate($date);
        $this->assertSiteIsPlannable($site);
        $excluded = $this->normalizeExcludedEmployeeIds($excludedEmployeeIds);
        $this->assertExclusionsAreMembers($team, $workDate, $excluded);
        $this->assertNoExactDuplicate($site, $team, $workDate, $halfDay);

        return DB::transaction(function () use ($site, $team, $workDate, $halfDay, $excluded, $actor): PlanningAssignment {
            try {
                $assignment = PlanningAssignment::query()->create([
                    'site_id' => $site->getKey(),
                    'team_id' => $team->getKey(),
                    'work_date' => $workDate->toDateString(),
                    'half_day' => $halfDay,
                ]);
            } catch (QueryException $exception) {
                if ($this->isDuplicateSlotViolation($exception)) {
                    throw $this->duplicateSlotValidation();
                }

                throw $exception;
            }

            $this->syncExclusions($assignment, $excluded);
            $assignment->load(['site', 'team', 'exclusions']);

            $warnings = $this->detectWarnings($assignment);

            $this->audit->record(
                AuditAction::PlanningAssignmentCreated,
                $assignment,
                [
                    'site_id' => $site->getKey(),
                    'team_id' => $team->getKey(),
                    'work_date' => $workDate->toDateString(),
                    'half_day' => $halfDay->value,
                    'excluded_employee_ids' => $excluded,
                ],
                null,
                $actor,
                ['overlaps' => $this->traceableWarnings($warnings)],
            );

            return $assignment;
        });
    }

    /**
     * @param  array<int, mixed>  $excludedEmployeeIds
     */
    public function update(
        PlanningAssignment $assignment,
        Site $site,
        Team $team,
        string $date,
        PlanningHalfDay $halfDay,
        array $excludedEmployeeIds,
        User $actor,
    ): PlanningAssignment {
        $this->assertCanManage($actor, $site, $team);

        $workDate = $this->parseWorkDate($date);
        $this->assertSiteIsPlannable($site);
        $excluded = $this->normalizeExcludedEmployeeIds($excludedEmployeeIds);
        $this->assertExclusionsAreMembers($team, $workDate, $excluded);
        $this->assertNoExactDuplicate($site, $team, $workDate, $halfDay, $assignment->getKey());

        return DB::transaction(function () use ($assignment, $site, $team, $workDate, $halfDay, $excluded, $actor): PlanningAssignment {
            /** @var PlanningAssignment $assignment */
            $assignment = PlanningAssignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $assignment->fill([
                'site_id' => $site->getKey(),
                'team_id' => $team->getKey(),
                'work_date' => $workDate->toDateString(),
                'half_day' => $halfDay,
            ]);

            $changed = array_keys($assignment->getDirty());

            if ($changed !== []) {
                try {
                    $assignment->save();
                } catch (QueryException $exception) {
                    if ($this->isDuplicateSlotViolation($exception)) {
                        throw $this->duplicateSlotValidation();
                    }

                    throw $exception;
                }
            }

            $exclusionsChanged = $this->syncExclusions($assignment, $excluded);
            $assignment->load(['site', 'team', 'exclusions']);

            $warnings = $this->detectWarnings($assignment);

            if ($changed !== [] || $exclusionsChanged) {
                $auditChanges = [
                    'changed_fields' => $changed,
                    'site_id' => $site->getKey(),
                    'team_id' => $team->getKey(),
                    'work_date' => $workDate->toDateString(),
                    'half_day' => $halfDay->value,
                    'excluded_employee_ids' => $excluded,
                ];

                if ($exclusionsChanged) {
                    $auditChanges['changed_fields'][] = 'excluded_employee_ids';
                }

                $this->audit->record(
                    AuditAction::PlanningAssignmentUpdated,
                    $assignment,
                    $auditChanges,
                    null,
                    $actor,
                    ['overlaps' => $this->traceableWarnings($warnings)],
                );
            }

            return $assignment;
        });
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     assignment_id: int,
     *     site_id: int,
     *     team_id: int,
     *     site_name: string,
     *     message: string,
     *     employee_id?: int,
     *     employee_name?: string
     * }>
     */
    public function warningsFor(PlanningAssignment $assignment, User $actor): array
    {
        Gate::forUser($actor)->authorize('view', $assignment);

        $assignment->load(['site', 'team', 'exclusions']);

        return $this->detectWarnings($assignment);
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     assignment_id: int,
     *     site_id: int,
     *     team_id: int,
     *     site_name: string,
     *     message: string,
     *     employee_id?: int,
     *     employee_name?: string
     * }>
     */
    private function detectWarnings(PlanningAssignment $assignment): array
    {
        $members = $this->roster->members($assignment->team, $assignment->work_date)->keyBy('id');
        $excludedIds = $assignment->exclusions->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id);
        $memberIds = $members->keys()->map(static fn (mixed $id): int => (int) $id)->diff($excludedIds)->values();

        $others = PlanningAssignment::query()
            ->whereKeyNot($assignment->getKey())
            ->where('work_date', $assignment->work_date->toDateString())
            ->where('half_day', $assignment->half_day->value)
            ->with(['site', 'team', 'exclusions'])
            ->orderBy('id')
            ->get();

        $warnings = [];

        foreach ($others as $other) {
            $siteName = $other->site->name;
            $teamName = $other->team->name;

            if ($other->team_id === $assignment->team_id) {
                $warnings[] = [
                    'type' => 'team',
                    'assignment_id' => (int) $other->getKey(),
                    'site_id' => (int) $other->site_id,
                    'team_id' => (int) $other->team_id,
                    'site_name' => $siteName,
                    'message' => sprintf(
                        'L’équipe %s est déjà affectée au chantier %s le %s (%s).',
                        $teamName,
                        $siteName,
                        $assignment->work_date->format('d/m/Y'),
                        $assignment->half_day->label(),
                    ),
                ];
            }

            $otherMembers = $this->roster->members($other->team, $assignment->work_date)->keyBy('id');
            $otherExcludedIds = $other->exclusions->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id);
            $otherMemberIds = $otherMembers->keys()
                ->map(static fn (mixed $id): int => (int) $id)
                ->diff($otherExcludedIds);

            foreach ($memberIds->intersect($otherMemberIds) as $employeeId) {
                $employee = $members->get($employeeId) ?? $otherMembers->get($employeeId);
                $employeeName = $employee instanceof Employee ? $employee->fullName() : 'Salarié #'.$employeeId;

                $warnings[] = [
                    'type' => 'employee',
                    'assignment_id' => (int) $other->getKey(),
                    'site_id' => (int) $other->site_id,
                    'team_id' => (int) $other->team_id,
                    'employee_id' => (int) $employeeId,
                    'employee_name' => $employeeName,
                    'site_name' => $siteName,
                    'message' => sprintf(
                        '%s est déjà planifié sur le chantier %s le %s (%s).',
                        $employeeName,
                        $siteName,
                        $assignment->work_date->format('d/m/Y'),
                        $assignment->half_day->label(),
                    ),
                ];
            }
        }

        return $warnings;
    }

    /**
     * L'audit conserve les identifiants nécessaires à la traçabilité, jamais
     * les noms de salariés ni les messages destinés à l'interface.
     *
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<int, array<string, int|string>>
     */
    private function traceableWarnings(array $warnings): array
    {
        return array_map(static function (array $warning): array {
            $trace = [
                'type' => (string) $warning['type'],
                'assignment_id' => (int) $warning['assignment_id'],
                'site_id' => (int) $warning['site_id'],
                'team_id' => (int) $warning['team_id'],
            ];

            if (isset($warning['employee_id'])) {
                $trace['employee_id'] = (int) $warning['employee_id'];
            }

            return $trace;
        }, $warnings);
    }

    /**
     * @param  array<int, int>  $excludedEmployeeIds
     */
    private function syncExclusions(PlanningAssignment $assignment, array $excludedEmployeeIds): bool
    {
        $current = $assignment->exclusions()
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $toDelete = array_values(array_diff($current, $excludedEmployeeIds));
        $toAdd = array_values(array_diff($excludedEmployeeIds, $current));

        if ($toDelete !== []) {
            $assignment->exclusions()->whereIn('employee_id', $toDelete)->delete();
        }

        foreach ($toAdd as $employeeId) {
            $assignment->exclusions()->create(['employee_id' => $employeeId]);
        }

        $assignment->unsetRelation('exclusions');

        return $toDelete !== [] || $toAdd !== [];
    }

    private function assertCanManage(User $actor, Site $site, Team $team): void
    {
        Gate::forUser($actor)->authorize(Permission::PlanningManage->value);
        Gate::forUser($actor)->authorize('view', $site);
        Gate::forUser($actor)->authorize('view', $team);
    }

    private function parseWorkDate(string $date): CarbonImmutable
    {
        try {
            return DatePeriod::parse($date);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['work_date' => $exception->getMessage()]);
        }
    }

    private function assertSiteIsPlannable(Site $site): void
    {
        if ($site->isArchived()) {
            throw ValidationException::withMessages([
                'site_id' => 'Un chantier archivé ne peut pas recevoir d’affectation de planning.',
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $excludedEmployeeIds
     * @return array<int, int>
     */
    private function normalizeExcludedEmployeeIds(array $excludedEmployeeIds): array
    {
        $normalized = [];

        foreach ($excludedEmployeeIds as $employeeId) {
            if (is_int($employeeId)) {
                $normalized[] = $employeeId;

                continue;
            }

            if (is_string($employeeId) && ctype_digit($employeeId)) {
                $normalized[] = (int) $employeeId;

                continue;
            }

            throw ValidationException::withMessages([
                'excluded_employee_ids' => 'Un identifiant de salarié exclu est invalide.',
            ]);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * @param  array<int, int>  $excludedEmployeeIds
     */
    private function assertExclusionsAreMembers(
        Team $team,
        CarbonImmutable $date,
        array $excludedEmployeeIds,
    ): void {
        if ($excludedEmployeeIds === []) {
            return;
        }

        $memberIds = $this->roster->members($team, $date)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $invalid = array_values(array_diff($excludedEmployeeIds, $memberIds));

        if ($invalid === []) {
            return;
        }

        $names = Employee::query()
            ->whereIn('id', $invalid)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(5)
            ->get()
            ->map(static fn (Employee $employee): string => $employee->fullName())
            ->implode(', ');

        $remaining = count($invalid) - 5;

        throw ValidationException::withMessages([
            'excluded_employee_ids' => sprintf(
                'Salarié(s) exclu(s) non membre(s) de l’équipe à cette date : %s%s.',
                $names,
                $remaining > 0 ? sprintf(', et %d autre(s)', $remaining) : '',
            ),
        ]);
    }

    private function assertNoExactDuplicate(
        Site $site,
        Team $team,
        CarbonImmutable $date,
        PlanningHalfDay $halfDay,
        ?int $exceptAssignmentId = null,
    ): void {
        $query = PlanningAssignment::query()
            ->where('site_id', $site->getKey())
            ->where('team_id', $team->getKey())
            ->where('work_date', $date->toDateString())
            ->where('half_day', $halfDay->value);

        if ($exceptAssignmentId !== null) {
            $query->whereKeyNot($exceptAssignmentId);
        }

        if ($query->exists()) {
            throw $this->duplicateSlotValidation();
        }
    }

    private function duplicateSlotValidation(): ValidationException
    {
        return ValidationException::withMessages([
            'team_id' => 'Cette équipe est déjà affectée à ce chantier sur cette demi-journée.',
        ]);
    }

    private function isDuplicateSlotViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
