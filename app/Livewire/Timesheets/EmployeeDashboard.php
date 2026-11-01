<?php

namespace App\Livewire\Timesheets;

use App\Enums\TimesheetStatus;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetQuery;
use App\Services\Timesheets\TimesheetSummary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord personnel du salarié.
 *
 * Seules les données de la fiche salarié liée au compte connecté sont lues.
 * L'accès est revérifié à chaque requête Livewire.
 */
#[Layout('layouts.workspace')]
class EmployeeDashboard extends Component
{
    public function booted(): void
    {
        Gate::authorize('viewAny', Timesheet::class);
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();
        $query = app(TimesheetQuery::class);
        $employeeId = $query->employeeIdFor($actor);
        $employee = $employeeId === null
            ? null
            : Employee::query()->find($employeeId);

        $timesheets = $employee instanceof Employee
            ? Timesheet::query()
                ->where('employee_id', $employee->getKey())
                ->with('segments')
                ->orderByDesc('period_start')
                ->get()
            : collect();

        $currentPeriod = Timesheet::weekFor(CarbonImmutable::now());
        $current = $timesheets->first(
            static fn (Timesheet $timesheet): bool => $timesheet->period_start?->isSameDay($currentPeriod['start']) ?? false,
        );

        $counts = [
            'pending' => $timesheets->whereIn('status', [
                TimesheetStatus::AControler,
                TimesheetStatus::PretePourValidation,
            ])->count(),
            'corrections' => $timesheets->where('status', TimesheetStatus::ACorriger)->count(),
            'drafts' => $timesheets->where('status', TimesheetStatus::Brouillon)->count(),
            'validated' => $timesheets->where('status', TimesheetStatus::Validee)->count(),
        ];

        $summary = app(TimesheetSummary::class);
        $history = app(TimesheetHistory::class);

        $recentEvents = $employee instanceof Employee
            ? $history->presentMany(
                $history->globalQuery()
                    ->whereIn('subject_id', $timesheets
                        ->pluck('id')
                        ->map(static fn (mixed $id): string => (string) $id)
                        ->all())
                    ->orderByDesc('sequence')
                    ->limit(8)
                    ->get(),
            )
            : [];

        return view('livewire.timesheets.employee.dashboard', [
            'employee' => $employee,
            'current' => $current,
            'currentTotal' => $current instanceof Timesheet ? $summary->totalMinutes($current) : 0,
            'counts' => $counts,
            'timesheets' => $timesheets->take(5),
            'recentEvents' => $recentEvents,
            'totalFormatter' => $summary,
        ]);
    }
}
