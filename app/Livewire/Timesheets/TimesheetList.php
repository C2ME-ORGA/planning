<?php

namespace App\Livewire\Timesheets;

use App\Enums\Permission;
use App\Enums\TimesheetStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetOverlapOverview;
use App\Services\Timesheets\TimesheetQuery;
use App\Services\Timesheets\TimesheetSummary;
use App\Support\DatePeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste filtrable des relevés accessibles à l'acteur.
 *
 * Le périmètre est appliqué en SQL par `TimesheetQuery` avant la pagination.
 * Les files de traitement (à contrôler, prêtes, en correction, validées,
 * chevauchements) réutilisent la même liste ; la file des chevauchements est
 * strictement réservée au Bureau/Direction.
 */
#[Layout('layouts.workspace')]
class TimesheetList extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'du', except: '')]
    public string $from = '';

    #[Url(as: 'au', except: '')]
    public string $to = '';

    #[Url(as: 'salarie', except: '')]
    public string $employeeId = '';

    #[Url(as: 'statut', except: '')]
    public string $status = '';

    public string $queue = 'all';

    /**
     * @var array<int, string>
     */
    private const QUEUES = [
        'all',
        'a-controler',
        'pretes',
        'a-corriger',
        'validees',
        'chevauchements',
    ];

    public function mount(string $queue = 'all'): void
    {
        $this->queue = in_array($queue, self::QUEUES, true) ? $queue : 'all';
        $this->assertQueueAccess();

        $queueStatus = $this->statusForQueue();

        if ($this->status === '' && $queueStatus instanceof TimesheetStatus) {
            $this->status = $queueStatus->value;
        }
    }

    public function booted(): void
    {
        Gate::authorize('viewAny', Timesheet::class);
        $this->assertQueueAccess();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEmployeeId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->from = $this->normalizeDate($this->from, 'from');
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->to = $this->normalizeDate($this->to, 'to');
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->from = '';
        $this->to = '';
        $this->employeeId = '';
        $queueStatus = $this->statusForQueue();
        $this->status = $queueStatus instanceof TimesheetStatus ? $queueStatus->value : '';
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();
        $queryService = app(TimesheetQuery::class);

        $query = $queryService->visibleTo($actor)
            ->with(['employee', 'segments']);

        $this->applyFilters($query);

        $timesheets = $query
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.timesheets.timesheet-list', [
            'timesheets' => $timesheets,
            'employees' => $queryService->employeeOptions($actor),
            'summary' => app(TimesheetSummary::class),
            'queueLabel' => $this->queueLabel(),
            'lockedStatus' => $this->statusForQueue(),
            'statusOptions' => TimesheetStatus::options(),
            'canReviewOverlaps' => Gate::forUser($actor)->allows('validateBatch', Timesheet::class),
        ]);
    }

    /**
     * @param  Builder<Timesheet>  $query
     */
    private function applyFilters(Builder $query): void
    {
        if ($this->search !== '') {
            $search = trim($this->search);

            $query->whereHas('employee', function (Builder $employee) use ($search): void {
                $employee->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');
            });
        }

        if ($this->from !== '' && $this->isValidDate($this->from)) {
            $query->where('period_start', '>=', $this->from);
        }

        if ($this->to !== '' && $this->isValidDate($this->to)) {
            $query->where('period_start', '<=', $this->to);
        }

        if ($this->employeeId !== '' && ctype_digit($this->employeeId)) {
            $query->where('employee_id', (int) $this->employeeId);
        }

        $queueStatus = $this->statusForQueue();

        if ($queueStatus instanceof TimesheetStatus) {
            $query->where('status', $queueStatus->value);
        } elseif ($this->status !== '' && TimesheetStatus::tryFrom($this->status) instanceof TimesheetStatus) {
            $query->where('status', $this->status);
        }

        if ($this->queue === 'chevauchements') {
            $ids = app(TimesheetOverlapOverview::class)
                ->timesheetsWithPendingOverlaps()
                ->pluck('id')
                ->all();

            $query->whereIn('id', $ids);
        }
    }

    private function statusForQueue(): ?TimesheetStatus
    {
        return match ($this->queue) {
            'a-controler' => TimesheetStatus::AControler,
            'pretes' => TimesheetStatus::PretePourValidation,
            'a-corriger' => TimesheetStatus::ACorriger,
            'validees' => TimesheetStatus::Validee,
            default => null,
        };
    }

    private function queueLabel(): string
    {
        return match ($this->queue) {
            'a-controler' => 'Relevés à contrôler',
            'pretes' => 'Relevés prêts pour validation',
            'a-corriger' => 'Relevés en correction',
            'validees' => 'Relevés validés',
            'chevauchements' => 'Relevés avec chevauchements à examiner',
            default => 'Tous les relevés',
        };
    }

    private function assertQueueAccess(): void
    {
        if ($this->queue === 'all') {
            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            return;
        }

        if ($this->queue === 'chevauchements'
            || ! $actor->hasPermission(Permission::TimesheetControl)) {
            abort(403);
        }
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

    private function isValidDate(string $value): bool
    {
        try {
            DatePeriod::parse($value);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }
}
