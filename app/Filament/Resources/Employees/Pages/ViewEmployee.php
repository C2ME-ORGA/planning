<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\EmployeeActions;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\RelationManagers\EmployeeLeadershipsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\EmployeeTeamAssignmentsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\EmploymentPeriodsRelationManager;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            EmployeeActions::linkAccount(),
            EmployeeActions::unlinkAccount(),
            EmployeeActions::addEmploymentPeriod(),
            EmployeeActions::recordExit(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            EmploymentPeriodsRelationManager::class,
            EmployeeTeamAssignmentsRelationManager::class,
            EmployeeLeadershipsRelationManager::class,
        ];
    }
}
