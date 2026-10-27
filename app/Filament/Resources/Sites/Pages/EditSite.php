<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Site $record */
        $record = $this->getRecord();
        abort_if($record->isArchived(), 403);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Site $record */
        /** @var User $actor */
        $actor = auth()->user();

        return app(SiteService::class)->update($record, $data, $actor);
    }
}
