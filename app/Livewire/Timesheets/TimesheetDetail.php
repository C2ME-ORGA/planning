<?php

namespace App\Livewire\Timesheets;

use App\Enums\OverlapReviewDecision;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Livewire\Concerns\InteractsWithTimesheetWorkflow;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Timesheets\LocalDateTimeResolver;
use App\Services\Timesheets\TimesheetActorHistory;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetOverlapOverview;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetSubmissionService;
use App\Services\Timesheets\TimesheetSummary;
use App\Services\Timesheets\TimesheetValidationService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Fiche détaillée d'un relevé, partagée par les trois espaces.
 *
 * Le composant recharge toujours le relevé depuis la base et revérifie
 * `view` avant toute lecture ou action. Les transitions et décisions sont
 * exclusivement déléguées aux services des lots A, B et C, qui réappliquent
 * leurs autorisations sous verrou.
 */
#[Layout('layouts.workspace')]
class TimesheetDetail extends Component
{
    use InteractsWithTimesheetWorkflow;

    #[Locked]
    public int $timesheetId = 0;

    public string $correctionReason = '';

    public string $readyReason = '';

    public string $directReason = '';

    public string $validationReason = '';

    public string $reopenReason = '';

    public string $overlapReason = '';

    public string $overlapDecision = OverlapReviewDecision::Accepted->value;

    public string $overlapPairKey = '';

    public bool $segmentFormVisible = false;

    public ?int $editingSegmentId = null;

    public string $segmentStartDate = '';

    public string $segmentEndDate = '';

    public string $segmentStartTime = '';

    public string $segmentEndTime = '';

    public string $segmentCategory = TimeCategory::TravailEffectif->value;

    public ?string $segmentStartOffsetMinutes = null;

    public ?string $segmentEndOffsetMinutes = null;

    public function mount(Timesheet $timesheet): void
    {
        Gate::authorize('view', $timesheet);
        $this->timesheetId = (int) $timesheet->getKey();
    }

    public function booted(): void
    {
        Gate::authorize('view', $this->timesheet());
    }

    public function submit(): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $this->performWorkflow(
            fn (): Timesheet => app(TimesheetSubmissionService::class)->submit($timesheet, $actor),
            'Relevé transmis pour contrôle.',
        );
    }

    public function resumeCorrection(): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $this->performWorkflow(
            fn (): Timesheet => app(TimesheetControlService::class)->resumeCorrection($timesheet, $actor),
            'Correction reprise : le relevé est de nouveau modifiable.',
        );
    }

    public function requestCorrection(): void
    {
        $this->validate([
            'correctionReason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $done = $this->performWorkflow(
            fn (): Timesheet => app(TimesheetControlService::class)
                ->requestCorrection($timesheet, $this->correctionReason, $actor),
            'Relevé retourné pour correction.',
        );

        if ($done) {
            $this->correctionReason = '';
        }
    }

    public function markReadyForValidation(): void
    {
        $this->validate([
            'readyReason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $done = $this->performWorkflow(
            fn (): Timesheet => app(TimesheetControlService::class)
                ->markReadyForValidation($timesheet, $this->readyReason, $actor),
            'Relevé marqué prêt pour validation.',
        );

        if ($done) {
            $this->readyReason = '';
        }
    }

    public function sendToValidation(): void
    {
        $this->validate([
            'directReason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $done = $this->performWorkflow(
            fn (): Timesheet => app(TimesheetControlService::class)
                ->sendToValidation($timesheet, $this->directReason, $actor),
            'Relevé transmis directement à la validation.',
        );

        if ($done) {
            $this->directReason = '';
            $this->resetSegmentForm();
        }
    }

    public function validateTimesheet(): void
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet();
        $selfValidation = $timesheet->employee?->user_id === $actor->getKey();

        if ($selfValidation) {
            $this->validate([
                'validationReason' => ['required', 'string', 'min:10', 'max:500'],
            ]);
        }

        $reason = $selfValidation ? $this->validationReason : null;

        $done = $this->performWorkflow(
            fn (): Timesheet => app(TimesheetValidationService::class)
                ->validate($timesheet, $actor, $reason),
            'Relevé validé.',
        );

        if ($done) {
            $this->validationReason = '';
        }
    }

    public function reopen(): void
    {
        $this->validate([
            'reopenReason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $done = $this->performWorkflow(
            fn (): Timesheet => app(TimesheetValidationService::class)
                ->reopen($timesheet, $this->reopenReason, $actor),
            'Relevé réouvert en brouillon.',
        );

        if ($done) {
            $this->reopenReason = '';
        }
    }

    public function reviewOverlap(string $pairKey): void
    {
        if (preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $pairKey, $matches) !== 1) {
            $this->addError('overlap', 'Le chevauchement sélectionné est invalide.');

            return;
        }

        $decisionEnum = OverlapReviewDecision::tryFrom($this->overlapDecision);

        if (! $decisionEnum instanceof OverlapReviewDecision) {
            $this->addError('overlap', 'La décision est invalide.');

            return;
        }

        $this->overlapPairKey = $pairKey;

        $this->validate([
            'overlapReason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $actor = $this->actor();
        $timesheet = $this->timesheet();

        $done = $this->performWorkflow(
            fn (): mixed => app(TimesheetOverlapReviewService::class)->review(
                $timesheet,
                (int) $matches[1],
                (int) $matches[2],
                $decisionEnum,
                $this->overlapReason,
                $actor,
            ),
            $decisionEnum === OverlapReviewDecision::Accepted
                ? 'Chevauchement accepté.'
                : 'Chevauchement rejeté.',
        );

        if ($done) {
            $this->overlapReason = '';
            $this->overlapPairKey = '';
        }
    }

    public function startAddingSegment(): void
    {
        $this->authorizeBureauCorrection();
        $this->resetSegmentForm();
        $this->segmentFormVisible = true;
        $this->resetErrorBag();
    }

    public function editSegment(int $segmentId): void
    {
        $timesheet = $this->authorizeBureauCorrection();
        $segment = $timesheet->segments()->whereKey($segmentId)->first();

        if (! $segment instanceof TimesheetSegment
            || $segment->start_at === null
            || $segment->end_at === null) {
            abort(404);
        }

        $start = BusinessTime::toLocal($segment->start_at);
        $end = BusinessTime::toLocal($segment->end_at);

        $this->editingSegmentId = (int) $segment->getKey();
        $this->segmentStartDate = $start->format('Y-m-d');
        $this->segmentStartTime = $start->format('H:i');
        $this->segmentEndDate = $end->format('Y-m-d');
        $this->segmentEndTime = $end->format('H:i');
        $this->segmentCategory = $segment->category->value;
        $this->segmentStartOffsetMinutes = (string) $segment->start_offset_minutes;
        $this->segmentEndOffsetMinutes = (string) $segment->end_offset_minutes;
        $this->segmentFormVisible = true;
        $this->resetErrorBag();
    }

    public function cancelSegmentEdit(): void
    {
        $this->resetSegmentForm();
        $this->resetErrorBag();
    }

    public function saveSegment(): void
    {
        $timesheet = $this->authorizeBureauCorrection();

        $this->validate([
            'segmentStartDate' => ['required', 'date_format:Y-m-d'],
            'segmentEndDate' => ['required', 'date_format:Y-m-d'],
            'segmentStartTime' => ['required', 'date_format:H:i'],
            'segmentEndTime' => ['required', 'date_format:H:i'],
            'segmentCategory' => ['required', Rule::in(array_keys(TimeCategory::options()))],
            'segmentStartOffsetMinutes' => ['nullable', 'integer', Rule::in([60, 120])],
            'segmentEndOffsetMinutes' => ['nullable', 'integer', Rule::in([60, 120])],
        ]);

        $resolver = app(LocalDateTimeResolver::class);

        try {
            $start = $resolver->resolve(
                $this->segmentStartDate,
                $this->segmentStartTime,
                $this->offset($this->segmentStartOffsetMinutes),
            );
            $end = $resolver->resolve(
                $this->segmentEndDate,
                $this->segmentEndTime,
                $this->offset($this->segmentEndOffsetMinutes),
            );
        } catch (RuntimeException $exception) {
            $this->addError('segmentStartTime', $exception->getMessage());

            return;
        }

        $actor = $this->actor();
        $segment = $this->editingSegmentId === null
            ? null
            : $timesheet->segments()->whereKey($this->editingSegmentId)->first();

        if ($this->editingSegmentId !== null && ! $segment instanceof TimesheetSegment) {
            abort(404);
        }

        $saved = $this->performWorkflow(function () use ($timesheet, $segment, $start, $end, $actor): void {
            $data = [
                'category' => $this->segmentCategory,
                'start_at' => $start,
                'end_at' => $end,
            ];

            $service = app(TimesheetSegmentService::class);

            if ($segment instanceof TimesheetSegment) {
                $service->update($segment, $data, $actor);

                return;
            }

            $service->add($timesheet, $data, $actor);
        }, $segment instanceof TimesheetSegment ? 'Segment modifié.' : 'Segment ajouté.');

        if ($saved) {
            $this->resetSegmentForm();
            $this->resetErrorBag();
        }
    }

    public function deleteSegment(int $segmentId): void
    {
        $timesheet = $this->authorizeBureauCorrection();
        $segment = $timesheet->segments()->whereKey($segmentId)->first();

        if (! $segment instanceof TimesheetSegment) {
            abort(404);
        }

        $deleted = $this->performWorkflow(
            function () use ($segment): void {
                app(TimesheetSegmentService::class)->delete($segment, $this->actor());
            },
            'Segment supprimé.',
        );

        if ($deleted && $this->editingSegmentId === $segmentId) {
            $this->resetSegmentForm();
        }
    }

    public function render(): View
    {
        $actor = $this->actor();
        $timesheet = $this->timesheet();
        $timesheet->loadMissing(['employee', 'segments']);

        $history = app(TimesheetHistory::class);
        $events = $history->eventsFor($timesheet);
        $summary = app(TimesheetSummary::class);

        $selfValidation = $timesheet->employee?->user_id === $actor->getKey();
        $overlaps = app(TimesheetOverlapOverview::class)->forTimesheet($timesheet);

        $canReview = Gate::forUser($actor)->allows('reviewOverlap', $timesheet);
        $canValidate = Gate::forUser($actor)->allows(
            'validate',
            [$timesheet, $selfValidation ? 'motif auto-validation' : null],
        );

        return view('livewire.timesheets.timesheet-detail', [
            'timesheet' => $timesheet,
            'events' => $history->presentMany($events),
            'latestCorrection' => $history->latestCorrectionRequest($events),
            'overlaps' => $overlaps,
            'durationWarnings' => $summary->durationWarnings($timesheet),
            'summary' => $summary,
            'totalMinutes' => $summary->totalMinutes($timesheet),
            'canSubmit' => Gate::forUser($actor)->allows('submit', $timesheet),
            'canResume' => Gate::forUser($actor)->allows('resumeCorrection', $timesheet),
            'canReturn' => Gate::forUser($actor)->allows('requestCorrection', [$timesheet, 'motif de contrôle']),
            'canMarkReady' => Gate::forUser($actor)->allows('markReadyForValidation', [$timesheet, 'motif de contrôle']),
            'canSendToValidation' => $timesheet->segments->isNotEmpty()
                && app(TimesheetActorHistory::class)->hasCorrectedInCurrentCycle($actor, $timesheet)
                && Gate::forUser($actor)->allows('sendToValidation', [$timesheet, 'motif de correction']),
            'canValidate' => $canValidate,
            'canEditBureauSegments' => $actor->role === Role::BureauDirection
                && ! $selfValidation
                && $timesheet->status->isEditable()
                && Gate::forUser($actor)->allows('update', $timesheet),
            'canReopen' => Gate::forUser($actor)->allows('reopen', [$timesheet, 'motif de réouverture']),
            'canReview' => $canReview,
            'canViewOverlapHistory' => $actor->hasPermission(Permission::TimesheetOverlapReview),
            'canEdit' => Gate::forUser($actor)->allows('update', $timesheet),
            'selfValidation' => $selfValidation,
            'isOwner' => $selfValidation,
            'decisionOptions' => [
                OverlapReviewDecision::Accepted->value => OverlapReviewDecision::Accepted->label(),
                OverlapReviewDecision::Rejected->value => OverlapReviewDecision::Rejected->label(),
            ],
        ]);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function timesheet(): Timesheet
    {
        return Timesheet::query()
            ->with(['employee', 'segments'])
            ->findOrFail($this->timesheetId);
    }

    /**
     * Interface de correction des segments réservée au Bureau/Direction.
     *
     * Les droits restent portés par la policy `update` et par le service
     * d'écriture ; ce garde empêche seulement un autre rôle d'utiliser cette
     * interface spécifique. Les écritures sont revalidées sous verrou.
     */
    private function authorizeBureauCorrection(): Timesheet
    {
        $actor = $this->actor();

        if ($actor->role !== Role::BureauDirection) {
            abort(403);
        }

        $timesheet = $this->timesheet();
        Gate::forUser($actor)->authorize('update', $timesheet);

        if (! $timesheet->status->isEditable()) {
            abort(403);
        }

        return $timesheet;
    }

    private function resetSegmentForm(): void
    {
        $timesheet = $this->timesheet();
        $default = $timesheet->period_start?->toDateString()
            ?? BusinessTime::toLocal(CarbonImmutable::now())->toDateString();

        $this->segmentFormVisible = false;
        $this->editingSegmentId = null;
        $this->segmentStartDate = $default;
        $this->segmentEndDate = $default;
        $this->segmentStartTime = '';
        $this->segmentEndTime = '';
        $this->segmentCategory = TimeCategory::TravailEffectif->value;
        $this->segmentStartOffsetMinutes = null;
        $this->segmentEndOffsetMinutes = null;
    }

    private function offset(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return (int) $value;
    }
}
