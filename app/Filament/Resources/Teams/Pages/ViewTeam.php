<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\RelationManagers\TeamAssignmentsRelationManager;
use App\Filament\Resources\Teams\RelationManagers\TeamLeadershipsRelationManager;
use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTeam extends ViewRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            TeamAssignmentsRelationManager::class,
            TeamLeadershipsRelationManager::class,
        ];
    }
}
