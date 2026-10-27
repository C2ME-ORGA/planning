<?php

namespace App\Filament\Resources\PlanningAssignments\Pages;

use App\Filament\Resources\PlanningAssignments\PlanningAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanningAssignments extends ListRecords
{
    protected static string $resource = PlanningAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(
                fn (): bool => auth()->user()?->can('create', PlanningAssignmentResource::getModel()) ?? false,
            ),
        ];
    }
}
