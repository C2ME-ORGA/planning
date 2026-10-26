<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        /** @var User $actor */
        $actor = auth()->user();

        if (array_key_exists('role', $data)) {
            abort_unless($actor->can('assignRole', $record), 403);

            $role = Role::from($data['role']);
            unset($data['role']);

            try {
                app(AccountAdministration::class)->changeRole($record, $role, $actor);
            } catch (Throwable $exception) {
                throw ValidationException::withMessages([
                    'data.role' => $exception->getMessage(),
                ]);
            }
        }

        $record->update($data);

        return $record;
    }
}
