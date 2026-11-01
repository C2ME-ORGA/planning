<?php

namespace App\Livewire\Timesheets;

use App\Enums\OverlapReviewDecision;
use App\Enums\Permission;
use App\Livewire\Concerns\InteractsWithTimesheetWorkflow;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetHistory;
use App\Services\Timesheets\TimesheetOverlapOverview;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetSubmissionService;
use App\Services\Timesheets\TimesheetSummary;
use App\Services\Timesheets\TimesheetValidationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

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

    public string $validationReason = '';

    public string $reopenReason = '';

    public string $overlapReason = '';

    public string $overlapDecision = OverlapReviewDecision::Accepted->value;

    public string $overlapPairKey = '';

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
            'canValidate' => $canValidate,
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
}
