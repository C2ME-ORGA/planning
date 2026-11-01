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
use Illuminate\Database\Eloquent\Builder;
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
            : null;

        $currentPeriod = Timesheet::weekFor(CarbonImmutable::now());

        $current = $timesheets instanceof Builder
            ? (clone $timesheets)
                ->where('period_start', $currentPeriod['start']->toDateString())
                ->with('segments')
                ->first()
            : null;

        $counts = [
            'pending' => 0,
            'corrections' => 0,
            'drafts' => 0,
            'validated' => 0,
        ];

        if ($timesheets instanceof Builder) {
            $rows = (clone $timesheets)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $counts = [
                'pending' => (int) ($rows[TimesheetStatus::AControler->value] ?? 0)
                    + (int) ($rows[TimesheetStatus::PretePourValidation->value] ?? 0),
                'corrections' => (int) ($rows[TimesheetStatus::ACorriger->value] ?? 0),
                'drafts' => (int) ($rows[TimesheetStatus::Brouillon->value] ?? 0),
                'validated' => (int) ($rows[TimesheetStatus::Validee->value] ?? 0),
            ];
        }

        $summary = app(TimesheetSummary::class);
        $history = app(TimesheetHistory::class);

        $recentEvents = $employee instanceof Employee
            ? $history->presentMany(
                $history->globalQuery()
                    ->whereIn('subject_id', function ($query) use ($employee): void {
                        $query->selectRaw('CAST(id AS CHAR(20))')
                            ->from('timesheets')
                            ->where('employee_id', $employee->getKey());
                    })
                    ->orderByDesc('sequence')
                    ->limit(8)
                    ->get(),
            )
            : [];

        $recentTimesheets = $timesheets instanceof Builder
            ? (clone $timesheets)
                ->with('segments')
                ->orderByDesc('period_start')
                ->limit(5)
                ->get()
            : collect();

        return view('livewire.timesheets.employee.dashboard', [
            'employee' => $employee,
            'current' => $current,
            'currentTotal' => $current instanceof Timesheet ? $summary->totalMinutes($current) : 0,
            'counts' => $counts,
            'timesheets' => $recentTimesheets,
            'recentEvents' => $recentEvents,
            'totalFormatter' => $summary,
        ]);
    }
}
