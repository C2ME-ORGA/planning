<?php

namespace App\Livewire\Timesheets;

use App\Enums\TimeCategory;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\InteractsWithTimesheetWorkflow;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Timesheets\LocalDateTimeResolver;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetOverlapOverview;
use App\Services\Timesheets\TimesheetQuery;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetService;
use App\Services\Timesheets\TimesheetSubmissionService;
use App\Services\Timesheets\TimesheetSummary;
use App\Support\BusinessTime;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Planning hebdomadaire et saisie des segments d'un salarié.
 *
 * Toutes les écritures passent par les services des lots 2B/2C : le composant
 * ne décide ni du statut éditable, ni des bornes de semaine, ni des
 * chevauchements. Les avertissements de chevauchement sont présentés sans
 * bloquer la saisie et sans jamais déplacer un horaire.
 */
#[Layout('layouts.workspace')]
class WeeklyTimesheet extends Component
{
    use InteractsWithTimesheetWorkflow;

    #[Locked]
    public string $week = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $startTime = '';

    public string $endTime = '';

    public string $category = TimeCategory::TravailEffectif->value;

    public ?string $startOffsetMinutes = null;

    public ?string $endOffsetMinutes = null;

    public ?int $editingSegmentId = null;

    public function mount(?string $week = null): void
    {
        try {
            $period = Timesheet::weekFor($week === null || trim($week) === ''
                ? CarbonImmutable::now()
                : $week);
        } catch (BusinessRuleException) {
            abort(404);
        }

        $this->week = $period['start']->toDateString();
        $this->resetSegmentForm();
    }

    public function booted(): void
    {
        Gate::authorize('viewAny', Timesheet::class);
    }

    public function previousWeek(): void
    {
        $this->goToWeek(-1);
    }

    public function nextWeek(): void
    {
        $this->goToWeek(1);
    }

    public function currentWeek(): void
    {
        $this->redirectToWeek(Timesheet::weekFor(CarbonImmutable::now())['start']);
    }

    public function selectDay(string $date): void
    {
        if (! $this->isDateInWeek($date)) {
            $this->addError('startDate', 'La date doit appartenir à la semaine affichée.');

            return;
        }

        $this->editingSegmentId = null;
        $this->startDate = $date;
        $this->endDate = $date;
        $this->startTime = '';
        $this->endTime = '';
        $this->startOffsetMinutes = null;
        $this->endOffsetMinutes = null;
    }

    public function openWeek(): void
    {
        $actor = $this->actor();
        $employee = $this->employee($actor);

        if (! $employee instanceof Employee) {
            abort(403);
        }

        $this->performWorkflow(
            fn (): Timesheet => app(TimesheetService::class)->openFor($employee, $this->week, $actor),
            'Semaine ouverte. Vous pouvez saisir vos segments.',
        );
    }

    public function editSegment(int $segmentId): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet($actor);

        if (! $timesheet instanceof Timesheet) {
            abort(404);
        }

        Gate::forUser($actor)->authorize('update', $timesheet);

        $segment = $timesheet->segments()
            ->whereKey($segmentId)
            ->first();

        if (! $segment instanceof TimesheetSegment
            || $segment->start_at === null
            || $segment->end_at === null) {
            abort(404);
        }

        $start = BusinessTime::toLocal($segment->start_at);
        $end = BusinessTime::toLocal($segment->end_at);

        $this->editingSegmentId = (int) $segment->getKey();
        $this->startDate = $start->format('Y-m-d');
        $this->startTime = $start->format('H:i');
        $this->endDate = $end->format('Y-m-d');
        $this->endTime = $end->format('H:i');
        $this->category = $segment->category->value;
        $this->startOffsetMinutes = (string) $segment->start_offset_minutes;
        $this->endOffsetMinutes = (string) $segment->end_offset_minutes;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->resetSegmentForm();
        $this->resetErrorBag();
    }

    public function saveSegment(): void
    {
        $this->validate([
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i'],
            'category' => ['required', Rule::in(array_keys(TimeCategory::options()))],
            'startOffsetMinutes' => ['nullable', 'integer', Rule::in([60, 120])],
            'endOffsetMinutes' => ['nullable', 'integer', Rule::in([60, 120])],
        ]);

        if (! $this->isDateInWeek($this->startDate) || ! $this->isDateInWeek($this->endDate)) {
            $this->addError('startDate', 'Les dates du segment doivent appartenir à la semaine affichée.');

            return;
        }

        $resolver = app(LocalDateTimeResolver::class);

        try {
            $start = $resolver->resolve(
                $this->startDate,
                $this->startTime,
                $this->offset($this->startOffsetMinutes),
            );
            $end = $resolver->resolve(
                $this->endDate,
                $this->endTime,
                $this->offset($this->endOffsetMinutes),
            );
        } catch (BusinessRuleException $exception) {
            $this->addError('startTime', $exception->getMessage());

            return;
        }

        $actor = $this->actor();
        $timesheet = $this->timesheet($actor);

        if (! $timesheet instanceof Timesheet) {
            $this->addError('workflow', 'Ouvrez d’abord la semaine avant de saisir un segment.');

            return;
        }

        $segment = $this->editingSegmentId === null
            ? null
            : $timesheet->segments()->whereKey($this->editingSegmentId)->first();

        if ($this->editingSegmentId !== null && ! $segment instanceof TimesheetSegment) {
            abort(404);
        }

        $service = app(TimesheetSegmentService::class);

        $saved = $this->performWorkflow(function () use ($service, $timesheet, $segment, $start, $end, $actor): void {
            $data = [
                'category' => $this->category,
                'start_at' => $start,
                'end_at' => $end,
            ];

            if ($segment instanceof TimesheetSegment) {
                $service->update($segment, $data, $actor);

                return;
            }

            $service->add($timesheet, $data, $actor);
        }, $segment instanceof TimesheetSegment ? 'Segment modifié.' : 'Segment ajouté.');

        if ($saved) {
            $this->resetSegmentForm();
        }
    }

    public function deleteSegment(int $segmentId): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet($actor);

        if (! $timesheet instanceof Timesheet) {
            abort(404);
        }

        $segment = $timesheet->segments()->whereKey($segmentId)->first();

        if (! $segment instanceof TimesheetSegment) {
            abort(404);
        }

        $deleted = $this->performWorkflow(
            function () use ($segment, $actor): void {
                app(TimesheetSegmentService::class)->delete($segment, $actor);
            },
            'Segment supprimé.',
        );

        if ($deleted && $this->editingSegmentId === $segmentId) {
            $this->resetSegmentForm();
        }
    }

    public function submit(): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet($actor);

        if (! $timesheet instanceof Timesheet) {
            $this->addError('workflow', 'Ouvrez d’abord la semaine avant de la transmettre.');

            return;
        }

        $this->performWorkflow(
            fn (): Timesheet => app(TimesheetSubmissionService::class)->submit($timesheet, $actor),
            'Relevé transmis pour contrôle.',
        );
    }

    public function resumeCorrection(): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet($actor);

        if (! $timesheet instanceof Timesheet) {
            abort(404);
        }

        $this->performWorkflow(
            fn (): Timesheet => app(TimesheetControlService::class)->resumeCorrection($timesheet, $actor),
            'Correction reprise : le relevé est de nouveau modifiable.',
        );
    }

    public function render(): View
    {
        $actor = $this->actor();
        $employee = $this->employee($actor);
        $timesheet = $employee instanceof Employee ? $this->timesheet($actor) : null;
        $summary = app(TimesheetSummary::class);
        $history = app(TimesheetHistory::class);
        $overview = app(TimesheetOverlapOverview::class);

        $events = $timesheet instanceof Timesheet
            ? $history->eventsFor($timesheet)
            : collect();

        $weekStart = CarbonImmutable::parse($this->week, BusinessTime::TIMEZONE);
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $days[] = $weekStart->addDays($offset);
        }

        $segmentsByDay = $timesheet instanceof Timesheet
            ? $summary->segmentsByDay($timesheet)
            : [];
        $minutesByDay = $timesheet instanceof Timesheet
            ? $summary->minutesByDay($timesheet)
            : [];

        $canOpen = $employee instanceof Employee
            && ! $timesheet instanceof Timesheet
            && Gate::forUser($actor)->allows(
                'createFor',
                new Timesheet(['employee_id' => $employee->getKey()]),
            );

        return view('livewire.timesheets.weekly-timesheet', [
            'employee' => $employee,
            'timesheet' => $timesheet,
            'days' => $days,
            'segmentsByDay' => $segmentsByDay,
            'minutesByDay' => $minutesByDay,
            'totalMinutes' => $timesheet instanceof Timesheet ? $summary->totalMinutes($timesheet) : 0,
            'summary' => $summary,
            'latestCorrection' => $history->latestCorrectionRequest($events),
            'durationWarnings' => $timesheet instanceof Timesheet ? $summary->durationWarnings($timesheet) : [],
            'overlaps' => $timesheet instanceof Timesheet ? $overview->forTimesheet($timesheet) : [],
            'canOpen' => $canOpen,
            'canEdit' => $timesheet instanceof Timesheet
                && Gate::forUser($actor)->allows('update', $timesheet),
            'canSubmit' => $timesheet instanceof Timesheet
                && Gate::forUser($actor)->allows('submit', $timesheet),
            'canResume' => $timesheet instanceof Timesheet
                && Gate::forUser($actor)->allows('resumeCorrection', $timesheet),
        ]);
    }

    private function goToWeek(int $direction): void
    {
        $this->redirectToWeek(
            CarbonImmutable::parse($this->week, BusinessTime::TIMEZONE)->addWeeks($direction),
        );
    }

    private function redirectToWeek(CarbonImmutable $date): void
    {
        $this->redirectRoute('workspace.employee.week', [
            'week' => Timesheet::weekFor($date)['start']->toDateString(),
        ], navigate: true);
    }

    private function resetSegmentForm(): void
    {
        $weekStart = CarbonImmutable::parse($this->week, BusinessTime::TIMEZONE);
        $today = BusinessTime::toLocal(CarbonImmutable::now())->startOfDay();
        $default = $today->greaterThanOrEqualTo($weekStart)
            && $today->lessThanOrEqualTo($weekStart->addDays(6))
            ? $today
            : $weekStart;

        $this->editingSegmentId = null;
        $this->startDate = $default->toDateString();
        $this->endDate = $default->toDateString();
        $this->startTime = '';
        $this->endTime = '';
        $this->category = TimeCategory::TravailEffectif->value;
        $this->startOffsetMinutes = null;
        $this->endOffsetMinutes = null;
    }

    private function isDateInWeek(string $date): bool
    {
        try {
            $parsed = DatePeriod::parse($date);
        } catch (BusinessRuleException) {
            return false;
        }

        $start = DatePeriod::parse($this->week);

        return $parsed->greaterThanOrEqualTo($start)
            && $parsed->lessThanOrEqualTo($start->addDays(6));
    }

    private function offset(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function employee(User $actor): ?Employee
    {
        $employeeId = app(TimesheetQuery::class)->employeeIdFor($actor);

        return $employeeId === null
            ? null
            : Employee::query()->find($employeeId);
    }

    private function timesheet(User $actor): ?Timesheet
    {
        $employee = $this->employee($actor);

        if (! $employee instanceof Employee) {
            return null;
        }

        return Timesheet::query()
            ->where('employee_id', $employee->getKey())
            ->where('period_start', $this->week)
            ->with('segments')
            ->first();
    }
}
