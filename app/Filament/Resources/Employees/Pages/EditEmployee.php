<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Employee $record */
        /** @var User $actor */
        $actor = auth()->user();

        return app(EmployeeService::class)->update(
            $record,
            (string) $data['first_name'],
            (string) $data['last_name'],
            $actor,
        );
    }
}
