<?php

namespace App\Livewire\Timesheets;

use App\Enums\Permission;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetQuery;
use App\Services\Timesheets\TimesheetSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord du responsable d'équipe.
 *
 * Toutes les requêtes passent par la portée historique du périmètre : un chef
 * ne voit ni les relevés des autres équipes, ni les chevauchements (réservés au
 * Bureau/Direction).
 */
#[Layout('layouts.workspace')]
class TeamDashboard extends Component
{
    public function booted(): void
    {
        Gate::authorize('viewAny', Timesheet::class);

        /** @var User $actor */
        $actor = auth()->user();

        if (! $actor->hasPermission(Permission::TimesheetControl)) {
            abort(403);
        }
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();
        $query = app(TimesheetQuery::class);

        $visible = $query->visibleTo($actor);
        $counts = $this->countsByStatus($visible);

        $timesheets = (clone $visible)
            ->with(['employee', 'segments'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        $employeeCount = (clone $visible)
            ->reorder()
            ->select('employee_id')
            ->distinct()
            ->count('employee_id');

        return view('livewire.timesheets.team.dashboard', [
            'counts' => $counts,
            'timesheets' => $timesheets,
            'employeeCount' => $employeeCount,
            'summary' => app(TimesheetSummary::class),
        ]);
    }

    /**
     * @param  Builder<Timesheet>  $visible
     * @return array<string, int>
     */
    private function countsByStatus(Builder $visible): array
    {
        $rows = (clone $visible)
            ->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'to_control' => 0,
            'ready' => 0,
            'correction' => 0,
            'validated' => 0,
            'draft' => 0,
        ];

        foreach (TimesheetStatus::cases() as $status) {
            $value = (int) ($rows[$status->value] ?? 0);

            $counts[match ($status) {
                TimesheetStatus::AControler => 'to_control',
                TimesheetStatus::PretePourValidation => 'ready',
                TimesheetStatus::ACorriger => 'correction',
                TimesheetStatus::Validee => 'validated',
                TimesheetStatus::Brouillon => 'draft',
            }] = $value;
        }

        return $counts;
    }
}
