<?php

namespace App\Livewire\Timesheets\Bureau;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetHistory;
use App\Support\DatePeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal global en lecture seule des événements de relevés.
 *
 * Réservé au Bureau/Direction. Aucun événement n'est modifiable ni supprimable
 * (journal append-only). Les filtres sont appliqués côté serveur et la
 * pagination porte sur la requête filtrée.
 */
#[Layout('layouts.workspace')]
class Journal extends Component
{
    use WithPagination;

    private const ACTOR_IDS_CACHE_KEY = 'planning.timesheets.journal.actor_ids.v1';

    private const ACTOR_IDS_CACHE_TTL_MINUTES = 60;

    #[Url(as: 'du', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'au', except: '')]
    public string $dateTo = '';

    #[Url(as: 'salarie', except: '')]
    public string $employeeId = '';

    #[Url(as: 'releve', except: '')]
    public string $timesheetId = '';

    #[Url(as: 'acteur', except: '')]
    public string $actorId = '';

    #[Url(as: 'evenement', except: '')]
    public string $action = '';

    public function booted(): void
    {
        Gate::authorize('viewJournal', Timesheet::class);
    }

    public function updatedDateFrom(): void
    {
        $this->dateFrom = $this->normalizeDate($this->dateFrom, 'dateFrom');
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->dateTo = $this->normalizeDate($this->dateTo, 'dateTo');
        $this->resetPage();
    }

    public function updatedEmployeeId(): void
    {
        $this->employeeId = $this->numericOrEmpty($this->employeeId);
        $this->resetPage();
    }

    public function updatedTimesheetId(): void
    {
        $this->timesheetId = $this->numericOrEmpty($this->timesheetId);
        $this->resetPage();
    }

    public function updatedActorId(): void
    {
        $this->actorId = $this->numericOrEmpty($this->actorId);
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        if (! array_key_exists($this->action, TimesheetHistory::actionOptions())) {
            $this->action = '';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->employeeId = '';
        $this->timesheetId = '';
        $this->actorId = '';
        $this->action = '';
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function render(): View
    {
        $history = app(TimesheetHistory::class);
        $query = $history->globalQuery();

        if ($this->dateFrom !== '') {
            $query->whereDate('occurred_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('occurred_at', '<=', $this->dateTo);
        }

        if ($this->employeeId !== '') {
            $query->whereIn('subject_id', function ($subquery): void {
                $subquery->selectRaw('CAST(id AS CHAR(20))')
                    ->from('timesheets')
                    ->where('employee_id', (int) $this->employeeId);
            });
        }

        if ($this->timesheetId !== '') {
            $query->where('subject_id', $this->timesheetId);
        }

        if ($this->actorId !== '') {
            $query->where('actor_id', (int) $this->actorId);
        }

        if (array_key_exists($this->action, TimesheetHistory::actionOptions())) {
            $query->where('action', $this->action);
        }

        $events = $query
            ->orderByDesc('sequence')
            ->paginate(25);

        /** @var Collection<int, AuditEvent> $items */
        $items = $events->getCollection();
        $entries = $history->presentMany($items);

        $timesheetIds = $items
            ->pluck('subject_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Timesheet> $timesheets */
        $timesheets = Timesheet::query()
            ->whereIn('id', $timesheetIds)
            ->with('employee')
            ->get()
            ->keyBy(static fn (Timesheet $timesheet): int => (int) $timesheet->getKey());

        return view('livewire.timesheets.bureau.journal', [
            'entries' => $entries,
            'entriesPaginator' => $events,
            'timesheets' => $timesheets,
            'employees' => Employee::query()->orderBy('last_name')->orderBy('first_name')->get(),
            'actors' => User::query()->whereIn('id', $this->actorIds())->orderBy('name')->get(),
            'actionOptions' => TimesheetHistory::actionOptions(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function actorIds(): array
    {
        return Cache::remember(
            self::ACTOR_IDS_CACHE_KEY,
            now()->addMinutes(self::ACTOR_IDS_CACHE_TTL_MINUTES),
            static fn (): array => AuditEvent::query()
                ->whereNotNull('actor_id')
                ->where('subject_type', Timesheet::class)
                ->whereIn('action', TimesheetHistory::actions())
                ->select('actor_id')
                ->distinct()
                ->pluck('actor_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    private function normalizeDate(string $value, string $field): string
    {
        if (trim($value) === '') {
            $this->resetErrorBag($field);

            return '';
        }

        try {
            return DatePeriod::parse($value)->toDateString();
        } catch (BusinessRuleException $exception) {
            $this->addError($field, $exception->getMessage());

            return '';
        }
    }

    private function numericOrEmpty(string $value): string
    {
        return ctype_digit($value) && (int) $value > 0 ? (string) (int) $value : '';
    }
}
