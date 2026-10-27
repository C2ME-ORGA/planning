<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\User;
use App\Services\Sites\SiteService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        return app(SiteService::class)->create($data, $actor);
    }
}
