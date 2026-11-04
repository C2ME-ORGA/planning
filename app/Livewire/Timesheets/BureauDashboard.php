<?php

namespace App\Livewire\Timesheets;

use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetOverlapOverview;
use App\Services\Timesheets\TimesheetSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord central du Bureau/Direction.
 *
 * Les compteurs proviennent des données réelles et la page est refusée à tout
 * rôle qui ne peut pas lancer une validation en masse, c'est-à-dire hors
 * Bureau/Direction disposant de `timesheet.validate`.
 */
#[Layout('layouts.workspace')]
class BureauDashboard extends Component
{
    public function booted(): void
    {
        Gate::authorize('validateBatch', Timesheet::class);
    }

    public function render(): View
    {
        $counts = $this->countsByStatus();

        $overview = app(TimesheetOverlapOverview::class);
        $overlapSummary = $overview->pendingOverlapSummary();
        $pendingOverlaps = $overlapSummary['timesheets'];
        $overlapPairs = $overlapSummary['pending_pairs'];

        $history = app(TimesheetHistory::class);
        $recentEvents = $history->presentMany(
            $history->globalQuery()
                ->orderByDesc('sequence')
                ->limit(8)
                ->get(),
        );

        $recentTimesheets = Timesheet::query()
            ->with(['employee', 'segments'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return view('livewire.timesheets.bureau.dashboard', [
            'counts' => $counts,
            'overlapPairs' => $overlapPairs,
            'overlapTimesheets' => $pendingOverlaps->count(),
            'recentEvents' => $recentEvents,
            'recentTimesheets' => $recentTimesheets,
            'summary' => app(TimesheetSummary::class),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        $rows = Timesheet::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'to_control' => (int) ($rows[TimesheetStatus::AControler->value] ?? 0),
            'ready' => (int) ($rows[TimesheetStatus::PretePourValidation->value] ?? 0),
            'correction' => (int) ($rows[TimesheetStatus::ACorriger->value] ?? 0),
            'validated' => (int) ($rows[TimesheetStatus::Validee->value] ?? 0),
            'draft' => (int) ($rows[TimesheetStatus::Brouillon->value] ?? 0),
        ];
    }
}
