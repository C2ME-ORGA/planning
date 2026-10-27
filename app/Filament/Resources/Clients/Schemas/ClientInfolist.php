<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identité et contact')
                    ->schema([
                        TextEntry::make('company_name')->label('Raison sociale'),
                        TextEntry::make('contact_name')
                            ->label('Contact principal')
                            ->placeholder('Aucun contact renseigné'),
                        TextEntry::make('email')->label('E-mail')->copyable(),
                        TextEntry::make('phone')->label('Téléphone')->placeholder('—'),
                        TextEntry::make('archived_at')
                            ->label('Archivage')
                            ->badge()
                            ->state(fn (Client $record): string => $record->isArchived() ? 'Archivé' : 'Actif')
                            ->color(fn (Client $record): string => $record->isArchived() ? 'danger' : 'success')
                            ->placeholder('Actif'),
                    ])
                    ->columns(2),

                Section::make('Adresse')
                    ->schema([
                        TextEntry::make('full_address')
                            ->label('Adresse')
                            ->state(fn (Client $record): string => $record->fullAddress()),
                    ]),

                Section::make('Chantiers')
                    ->schema([
                        TextEntry::make('sites_count')
                            ->label('Chantiers associés')
                            ->state(fn (Client $record): int => $record->sites()->count()),
                    ]),
            ]);
    }
}
