<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Team;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Équipe')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_leader')
                    ->label('Responsable principal')
                    ->state(fn (Team $record): string => $record->currentLeadership()?->employee?->fullName() ?? '—'),

                TextColumn::make('assignments_count')
                    ->label('Affectations')
                    ->counts('assignments')
                    ->alignCenter(),

                TextColumn::make('leaderships_count')
                    ->label('Responsabilités')
                    ->counts('leaderships')
                    ->alignCenter(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Team $record): bool => auth()->user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->visible(fn (Team $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }
}
