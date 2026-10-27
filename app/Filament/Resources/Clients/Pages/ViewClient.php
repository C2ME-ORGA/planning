<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\Actions\ClientActions;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Client $record): bool => auth()->user()?->can('update', $record) ?? false),
            ClientActions::archive(),
            ClientActions::restore(),
        ];
    }
}
