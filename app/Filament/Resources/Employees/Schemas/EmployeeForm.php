<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identité')
                    ->description('Seules les informations strictement nécessaires sont demandées dans ce lot.')
                    ->schema([
                        TextInput::make('first_name')
                            ->label('Prénom')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('last_name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(100),
                    ])
                    ->columns(2),

                Section::make('Emploi et équipe')
                    ->description(
                        'Les périodes d’emploi, l’équipe principale, les affectations temporaires '
                        .'et les responsabilités se gèrent depuis la fiche du salarié après création. '
                        .'Ils sont historisés et ne peuvent pas être écrasés par ce formulaire.',
                    )
                    ->schema([]),
            ]);
    }
}
