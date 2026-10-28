<?php

namespace App\Filament\Resources\PlanningAssignments\Pages;

use App\Filament\Resources\PlanningAssignments\PlanningAssignmentResource;
use App\Models\PlanningAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentHistory;
use App\Services\Planning\PlanningAssignmentService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Consultation d'une affectation : détails, chevauchements et historique.
 *
 * La page est accessible à tous les lecteurs du planning, y compris pour une
 * affectation historique rattachée à un chantier archivé. La modification
 * reste réservée à `planning.manage` et interdite sur un chantier archivé.
 */
class ViewPlanningAssignment extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PlanningAssignmentResource::class;

    protected string $view = 'filament.resources.planning-assignments.view';

    public function mount(PlanningAssignment|int|string $record): void
    {
        $this->record = $this->resolveAssignment($record);
        $this->authorizeView();
    }

    public function hydrate(): void
    {
        $this->record = $this->resolveAssignment((int) $this->getRecord()->getKey());
        $this->authorizeView();
    }

    public function getTitle(): string|Htmlable
    {
        $assignment = $this->assignment();

        return 'Affectation du '.$assignment->work_date->format('d/m/Y').' — '.$assignment->half_day->label();
    }

    public function getBreadcrumb(): string
    {
        return 'Consultation';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function warnings(): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(PlanningAssignmentService::class)->warningsFor($this->assignment(), $actor);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(PlanningAssignmentHistory::class)->timeline($this->assignment(), $actor);
    }

    /**
     * @return array<int, EditAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Modifier')
                ->visible(fn (): bool => ! $this->assignment()->site->isArchived()
                    && (auth()->user()?->can('update', $this->assignment()) ?? false)),
        ];
    }

    private function assignment(): PlanningAssignment
    {
        $record = $this->getRecord();

        abort_unless($record instanceof PlanningAssignment, 404);

        return $record;
    }

    private function resolveAssignment(PlanningAssignment|int|string $record): PlanningAssignment
    {
        if ($record instanceof PlanningAssignment) {
            return $record->loadMissing(['site', 'team', 'exclusions']);
        }

        return PlanningAssignment::query()
            ->with(['site', 'team', 'exclusions'])
            ->findOrFail($record);
    }

    private function authorizeView(): void
    {
        abort_unless(auth()->user()?->can('view', $this->getRecord()) ?? false, 403);
    }
}
