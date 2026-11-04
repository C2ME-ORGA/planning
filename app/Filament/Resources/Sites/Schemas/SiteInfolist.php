<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Models\Site;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Consultation d'un chantier, y compris archivé. L'archivage est un état de
 * consultation historique : il ne réactive ni ne modifie le chantier.
 */
class SiteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chantier')
                ->schema([
                    TextEntry::make('name')->label('Nom du chantier'),
                    TextEntry::make('client.company_name')->label('Client associé'),
                    TextEntry::make('address')->label('Adresse du chantier'),
                    TextEntry::make('status')
                        ->label('Statut')
                        ->badge()
                        ->state(fn (Site $record): string => $record->statusLabel())
                        ->color(fn (Site $record): string => $record->statusEnum()?->color() ?? 'gray'),
                    TextEntry::make('archived_at')
                        ->label('Archivage')
                        ->badge()
                        ->state(fn (Site $record): string => $record->isArchived() ? 'Archivé' : 'Actif')
                        ->color(fn (Site $record): string => $record->isArchived() ? 'danger' : 'success'),
                ])
                ->columns(2),
        ]);
    }
}
