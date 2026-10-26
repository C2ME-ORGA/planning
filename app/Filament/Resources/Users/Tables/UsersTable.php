<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Filament\Resources\Users\Actions\AccountActions;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),

                TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label()),

                IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                TextColumn::make('activated_at')
                    ->label('Activé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Non activé')
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Rôle')
                    ->options(Role::options()),

                TernaryFilter::make('is_active')
                    ->label('Compte actif'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (User $record): bool => auth()->user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->visible(fn (User $record): bool => auth()->user()?->can('update', $record) ?? false),
                AccountActions::resendActivation(),
                AccountActions::deactivate(),
                AccountActions::reactivate(),
            ]);
    }
}
