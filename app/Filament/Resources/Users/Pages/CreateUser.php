<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Accounts\AccountProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(AccountProvisioner::class)->create(
            $data['name'],
            $data['email'],
            Role::from($data['role']),
            $actor,
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Compte créé. Un lien d’activation vient d’être envoyé.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
