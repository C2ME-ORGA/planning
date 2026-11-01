<?php

namespace App\Livewire\Timesheets\Bureau;

use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Timesheets\TimesheetBatchValidationService;
use App\Services\Timesheets\TimesheetSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Validation en masse des relevés prêts.
 *
 * La sélection est transmise telle quelle à `TimesheetBatchValidationService`,
 * seule autorité pour la borne de 50 relevés, la déduplication, la validation
 * individuelle et le résultat partiel. Aucun motif commun n'est demandé.
 */
#[Layout('layouts.workspace')]
class BatchValidation extends Component
{
    use WithPagination;

    /**
     * @var array<int, int|string>
     */
    public array $selected = [];

    /**
     * @var array{
     *     requested: int,
     *     succeeded: int,
     *     failed: int,
     *     partial: bool,
     *     results: array<int, array{
     *         timesheet_id: int,
     *         reference: string|null,
     *         result: 'success'|'failed',
     *         message: string|null
     *     }>
     * }|null
     */
    public ?array $results = null;

    public ?string $statusMessage = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function booted(): void
    {
        Gate::authorize('validateBatch', Timesheet::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function validateSelection(): void
    {
        $this->validate([
            'selected' => ['required', 'array', 'min:1', 'max:'.TimesheetBatchValidationService::MAX_BATCH_SIZE],
            'selected.*' => ['integer'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $this->results = app(TimesheetBatchValidationService::class)
                ->validate($actor, $this->selected);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError((string) $field, (string) ($messages[0] ?? 'Sélection refusée.'));
            }

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->selected = [];
        $this->statusMessage = sprintf(
            '%d relevé(s) traité(s) : %d validé(s), %d en échec.',
            $this->results['requested'],
            $this->results['succeeded'],
            $this->results['failed'],
        );
    }

    public function render(): View
    {
        $query = Timesheet::query()
            ->where('status', TimesheetStatus::PretePourValidation->value)
            ->with(['employee', 'segments']);

        if (trim($this->search) !== '') {
            $search = trim($this->search);

            $query->whereHas('employee', function (Builder $employee) use ($search): void {
                $employee->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');
            });
        }

        return view('livewire.timesheets.bureau.batch-validation', [
            'timesheets' => $query
                ->orderBy('period_start')
                ->orderBy('id')
                ->paginate(20),
            'summary' => app(TimesheetSummary::class),
        ]);
    }
}
