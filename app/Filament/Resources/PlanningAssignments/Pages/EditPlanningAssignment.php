<?php

namespace App\Filament\Resources\PlanningAssignments\Pages;

use App\Enums\PlanningHalfDay;
use App\Filament\Resources\PlanningAssignments\PlanningAssignmentResource;
use App\Filament\Support\PlanningWarningAlert;
use App\Models\PlanningAssignment;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPlanningAssignment extends EditRecord
{
    protected static string $resource = PlanningAssignmentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PlanningAssignment $record */
        $record = $this->getRecord();
        $record->loadMissing('site');

        abort_if($record->site?->isArchived(), 403);

        $data['excluded_employee_ids'] = $record->excludedEmployeeIds();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PlanningAssignment $record */
        /** @var User $actor */
        $actor = auth()->user();

        $site = Site::query()->find((int) $data['site_id']);
        $team = Team::query()->find((int) $data['team_id']);

        if (! $site instanceof Site) {
            throw ValidationException::withMessages(['site_id' => 'Chantier introuvable.']);
        }

        if (! $team instanceof Team) {
            throw ValidationException::withMessages(['team_id' => 'Équipe introuvable.']);
        }

        $service = app(PlanningAssignmentService::class);

        $assignment = $service->update(
            $record,
            $site,
            $team,
            (string) $data['work_date'],
            PlanningHalfDay::from((string) $data['half_day']),
            $this->excludedEmployeeIds($data),
            $actor,
        );

        PlanningWarningAlert::notify(
            $service->warningsFor($assignment, $actor),
            'Affectation de planning modifiée.',
        );

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function excludedEmployeeIds(array $data): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) ($data['excluded_employee_ids'] ?? []),
        ));
    }
}
