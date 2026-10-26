<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ajout de périodes d'emploi et contrôle de couverture.
 *
 * Les périodes sont append-only : une réembauche ajoute une nouvelle période
 * sans réécrire la précédente. L'absence de chevauchement est vérifiée dans une
 * transaction protégée par un verrou ciblé sur le salarié.
 */
class EmploymentPeriodService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeMutationLock $lock,
    ) {}

    public function add(Employee $employee, string $startDate, ?string $endDate, User $actor): EmploymentPeriod
    {
        $this->assertCanManage($actor);

        $start = DatePeriod::parse($startDate);
        $end = DatePeriod::parseNullable($endDate);
        DatePeriod::assertOrder($start, $end);

        return $this->lock->run($employee, function () use ($employee, $start, $end, $actor): EmploymentPeriod {
            return DB::transaction(function () use ($employee, $start, $end, $actor): EmploymentPeriod {
                $employee = Employee::query()->whereKey($employee->getKey())->firstOrFail();

                $this->assertNoOverlap($employee, $start, $end);

                $hadPeriods = $employee->employmentPeriods()->exists();
                $hasOpenPeriod = $employee->employmentPeriods()->whereNull('end_date')->exists();
                $latestEnd = $employee->employmentPeriods()->max('end_date');
                $isRehire = $hadPeriods
                    && ! $hasOpenPeriod
                    && is_string($latestEnd)
                    && $start->gt(CarbonImmutable::parse($latestEnd));

                $period = $employee->employmentPeriods()->create([
                    'start_date' => $start->toDateString(),
                    'end_date' => $end?->toDateString(),
                ]);

                $this->audit->record(
                    $isRehire ? AuditAction::EmployeeRehired : AuditAction::EmploymentPeriodAdded,
                    $employee,
                    [
                        'employment_period_id' => $period->getKey(),
                        'start_date' => $start->toDateString(),
                        'end_date' => $end?->toDateString(),
                    ],
                    null,
                    $actor,
                );

                return $period;
            });
        });
    }

    /**
     * La période d'emploi doit couvrir entièrement la plage demandée. Une
     * affectation ou une responsabilité ne peut pas traverser une rupture
     * d'emploi : une réembauche crée une nouvelle période et de nouveaux
     * enregistrements.
     */
    public function assertCovered(Employee $employee, string $startDate, ?string $endDate): EmploymentPeriod
    {
        $start = DatePeriod::parse($startDate);
        $end = DatePeriod::parseNullable($endDate);
        DatePeriod::assertOrder($start, $end);

        $period = EmploymentPeriod::query()
            ->where('employee_id', $employee->getKey())
            ->where('start_date', '<=', $start->toDateString())
            ->when(
                $end === null,
                static fn ($query) => $query->whereNull('end_date'),
                static fn ($query) => $query->where(static function ($nested) use ($end): void {
                    $nested->whereNull('end_date')->orWhere('end_date', '>=', $end->toDateString());
                }),
            )
            ->orderByDesc('start_date')
            ->first();

        if (! $period instanceof EmploymentPeriod) {
            throw new RuntimeException(
                'Aucune période d’emploi ne couvre toute la période demandée. Corrigez d’abord la période d’emploi.',
            );
        }

        return $period;
    }

    public function openPeriod(Employee $employee): ?EmploymentPeriod
    {
        return EmploymentPeriod::query()
            ->where('employee_id', $employee->getKey())
            ->whereNull('end_date')
            ->orderByDesc('start_date')
            ->first();
    }

    private function assertNoOverlap(Employee $employee, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $overlap = EmploymentPeriod::query()
            ->where('employee_id', $employee->getKey())
            ->where('start_date', '<=', DatePeriod::endOrMax($end))
            ->where(static function ($query) use ($start): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString());
            })
            ->exists();

        if ($overlap) {
            throw new RuntimeException(
                'Cette période d’emploi chevauche une période existante. Les dates de début et de fin sont inclusives.',
            );
        }
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::EmployeesManage)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à gérer les périodes d’emploi.');
        }
    }
}
