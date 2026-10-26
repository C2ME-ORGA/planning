<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Services\Employees\TeamService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Team $record */
        /** @var User $actor */
        $actor = auth()->user();

        return app(TeamService::class)->update($record, (string) $data['name'], $actor);
    }
}
