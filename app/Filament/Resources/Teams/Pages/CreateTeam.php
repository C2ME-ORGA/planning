<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Services\Employees\TeamService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(TeamService::class)->create((string) $data['name'], $actor);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Équipe créée.';
    }

    protected function getRedirectUrl(): string
    {
        /** @var Team $record */
        $record = $this->getRecord();

        return TeamResource::getUrl('view', ['record' => $record]);
    }
}
