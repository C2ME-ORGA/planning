<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Models\Employee;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->label('Prénom')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('Compte')
                    ->placeholder('Sans compte')
                    ->searchable(),

                TextColumn::make('employment_status')
                    ->label('Situation')
                    ->badge()
                    ->state(fn (Employee $record): string => $record->isEmployedAt() ? 'En emploi' : 'Hors emploi')
                    ->color(fn (Employee $record): string => $record->isEmployedAt() ? 'success' : 'gray'),

                TextColumn::make('primary_team')
                    ->label('Équipe principale')
                    ->state(fn (Employee $record): string => $record->currentPrimaryAssignment()?->team->name ?? '—'),

                TextColumn::make('current_leadership')
                    ->label('Responsabilité')
                    ->state(fn (Employee $record): string => $record->currentLeadership()?->team->name ?? '—'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Employee $record): bool => auth()->user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->visible(fn (Employee $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }
}
