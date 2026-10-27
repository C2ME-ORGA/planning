<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ClientDuplicateAlert;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Client $record */
        /** @var User $actor */
        $actor = auth()->user();

        $client = app(ClientService::class)->update($record, $data, $actor);

        ClientDuplicateAlert::notify($client, $actor);

        return $client;
    }
}
