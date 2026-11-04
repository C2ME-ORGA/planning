<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Resources\Employees\Actions\TeamLeadershipActions;
use App\Models\TeamLeadership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeLeadershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'leaderships';

    protected static ?string $title = 'Responsabilités principales';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('team.name')
                    ->label('Équipe')
                    ->searchable(),

                TextColumn::make('start_date')
                    ->label('Début')
                    ->date('d/m/Y'),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->placeholder('Ouverte'),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->state(fn (TeamLeadership $record): string => match (true) {
                        $record->isCancelled() => 'Annulée',
                        $record->end_date === null => 'Ouverte',
                        default => 'Clôturée',
                    })
                    ->color(fn (TeamLeadership $record): string => match (true) {
                        $record->isCancelled() => 'danger',
                        $record->end_date === null => 'success',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                TeamLeadershipActions::close(),
                TeamLeadershipActions::cancel(),
            ]);
    }
}
