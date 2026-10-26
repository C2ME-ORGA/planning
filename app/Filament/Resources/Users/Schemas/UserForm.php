<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Permission;
use App\Enums\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom affiché')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Adresse e-mail')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => Str::lower(trim((string) $state))),

                Select::make('role')
                    ->label('Rôle')
                    ->options(Role::options())
                    ->required()
                    ->native(false)
                    ->visible(fn (): bool => auth()->user()?->hasPermission(Permission::RolesAssign) ?? false)
                    ->helperText('Un seul rôle par compte. L’Administrateur technique n’a aucun droit métier par défaut.'),
            ]);
    }
}
