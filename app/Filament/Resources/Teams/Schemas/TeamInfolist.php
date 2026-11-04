<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Team;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TeamInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Équipe')
                    ->schema([
                        TextEntry::make('name')->label('Nom'),

                        TextEntry::make('current_leader')
                            ->label('Responsable principal actuel')
                            ->state(fn (Team $record): string => $record->currentLeadership()?->employee?->fullName() ?? 'Aucun'),

                        TextEntry::make('assignments_count')
                            ->label('Affectations historisées')
                            ->state(fn (Team $record): int => $record->assignments()->count()),

                        TextEntry::make('leaderships_count')
                            ->label('Responsabilités historisées')
                            ->state(fn (Team $record): int => $record->leaderships()->count()),
                    ])
                    ->columns(4),
            ]);
    }
}
