<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Actions\ClientActions;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Models\Client;
use App\Rules\ValidPhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
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
            Section::make('Identité et contact')->schema([
                TextInput::make('company_name')->label('Raison sociale')->required()->maxLength(190),
                TextInput::make('contact_name')->label('Contact principal')->maxLength(190),
                TextInput::make('email')->label('E-mail')->email()->required()->maxLength(254),
                TextInput::make('phone')
                    ->label('Téléphone')
                    ->tel()
                    ->rule(new ValidPhoneNumber)
                    ->maxLength(40),
            ])->columns(2),
            Section::make('Adresse')->schema([
                TextInput::make('street')->label('Rue et numéro')->required()->maxLength(255),
                TextInput::make('address_complement')->label('Complément d’adresse')->maxLength(255),
                TextInput::make('postal_code')->label('Code postal')->required()->maxLength(20),
                TextInput::make('city')->label('Ville')->required()->maxLength(120),
                TextInput::make('country')->label('Pays')->required()->default('France')->maxLength(100),
            ])->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('company_name')->columns([
            TextColumn::make('company_name')->label('Raison sociale')->searchable()->sortable(),
            TextColumn::make('contact_name')->label('Contact')->placeholder('—')->toggleable(),
            TextColumn::make('address')
                ->label('Adresse')
                ->state(fn (Client $record): string => $record->fullAddress())
                ->limit(45)
                ->searchable(['address', 'street', 'address_complement', 'postal_code', 'city']),
            TextColumn::make('phone')->label('Téléphone')->placeholder('—'),
            TextColumn::make('email')->label('E-mail')->searchable(),
            TextColumn::make('sites_count')->counts('sites')->label('Chantiers'),
            TextColumn::make('archived_at')
                ->label('Archivage')
                ->badge()
                ->state(fn (Client $record): string => $record->isArchived() ? 'Archivé' : 'Actif')
                ->color(fn (Client $record): string => $record->isArchived() ? 'danger' : 'success')
                ->description(fn (Client $record): ?string => $record->archived_at?->format('d/m/Y H:i')),
        ])->filters([
            TernaryFilter::make('archived_at')->label('Archivage')
                ->nullable()
                ->trueLabel('Archivés')->falseLabel('Actifs'),
        ])->recordActions([
            Action::make('view')
                ->label('Consulter')
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (Client $record): string => static::getUrl('view', ['record' => $record]))
                ->visible(fn (Client $record): bool => auth()->user()?->can('view', $record) ?? false),
            Action::make('edit')
                ->label('Modifier')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (Client $record): string => static::getUrl('edit', ['record' => $record]))
                ->visible(fn (Client $record): bool => auth()->user()?->can('update', $record) ?? false),
            ClientActions::archive(),
            ClientActions::restore(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
