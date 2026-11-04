<?php

namespace App\Filament\Resources\Teams\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom de l’équipe')
                    ->required()
                    ->maxLength(120)
                    ->unique(ignoreRecord: true),
            ]);
    }
}
