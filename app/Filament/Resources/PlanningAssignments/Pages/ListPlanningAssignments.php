<?php

namespace App\Filament\Resources\PlanningAssignments\Pages;

use App\Filament\Resources\PlanningAssignments\PlanningAssignmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlanningAssignments extends ListRecords
{
    protected static string $resource = PlanningAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mobilePlanning')
                ->label('Vue mobile')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->url(route('planning.mobile'))
                ->openUrlInNewTab(),

            CreateAction::make()->visible(
                fn (): bool => auth()->user()?->can('create', PlanningAssignmentResource::getModel()) ?? false,
            ),
        ];
    }
}
