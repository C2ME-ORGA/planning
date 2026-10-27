<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ClientDuplicateAlert;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        /** @var Client $client */
        $client = app(ClientService::class)->create($data, $actor);

        ClientDuplicateAlert::notify($client, $actor);

        return $client;
    }
}
