<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(EmployeeService::class)->create(
            (string) $data['first_name'],
            (string) $data['last_name'],
            $actor,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Fiche salarié créée.';
    }

    protected function getRedirectUrl(): string
    {
        /** @var Employee $record */
        $record = $this->getRecord();

        return EmployeeResource::getUrl('view', ['record' => $record]);
    }
}
