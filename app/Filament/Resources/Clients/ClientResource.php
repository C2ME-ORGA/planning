<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Opérationnel';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    protected static ?string $recordTitleAttribute = 'company_name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Coordonnées du client')->schema([
                TextInput::make('company_name')->label('Raison sociale')->required()->maxLength(190),
                TextInput::make('address')->label('Adresse')->required()->maxLength(500),
                TextInput::make('phone')->label('Téléphone')->tel()->maxLength(40),
                TextInput::make('email')->label('E-mail')->email()->maxLength(254),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('company_name')->columns([
            TextColumn::make('company_name')->label('Raison sociale')->searchable()->sortable(),
            TextColumn::make('address')->label('Adresse')->limit(45),
            TextColumn::make('phone')->label('Téléphone'),
            TextColumn::make('email')->label('E-mail')->searchable(),
            TextColumn::make('sites_count')->counts('sites')->label('Chantiers'),
        ])->recordActions([
            Action::make('edit')
                ->label('Modifier')
                ->url(fn (Client $record): string => static::getUrl('edit', ['record' => $record]))
                ->visible(fn (Client $record): bool => auth()->user()?->can('update', $record) ?? false),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
