<?php

namespace App\Filament\Resources\Sites;

use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\Actions\SiteActions;
use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Support\ActionFeedback;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Opérationnel';

    protected static ?string $navigationLabel = 'Chantiers';

    protected static ?string $modelLabel = 'chantier';

    protected static ?string $pluralModelLabel = 'chantiers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chantier')->schema([
                TextInput::make('name')->label('Nom du chantier')->required()->maxLength(190),
                TextInput::make('address')->label('Adresse du chantier')->required()->maxLength(500),
                Select::make('client_id')->label('Client associé')->relationship('client', 'company_name')->searchable()->preload()->required(),
                Select::make('status')
                    ->label('Statut du chantier')
                    ->options(SiteStatus::options())
                    ->default(SiteStatus::APreparer->value)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(
                        'Le statut initial est « À préparer ». Les changements de statut '
                        .'et les réouvertures se font depuis la liste des chantiers.',
                    ),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('client'))
            ->defaultSort('name')->columns([
                TextColumn::make('name')->label('Chantier')->searchable()->sortable(),
                TextColumn::make('client.company_name')->label('Client')->searchable(),
                TextColumn::make('address')->label('Adresse')->limit(45),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->state(fn (Site $record): string => $record->statusLabel())
                    ->color(fn (Site $record): string => $record->statusEnum()?->color() ?? 'gray'),
                TextColumn::make('archived_at')->label('Archivé le')->dateTime()->placeholder('Actif'),
            ])->filters([
                TernaryFilter::make('archived_at')->label('Archivage')
                    ->nullable()
                    ->trueLabel('Archivés')->falseLabel('Actifs'),
            ])->recordActions([
                SiteActions::transition(),
                SiteActions::reopen(),
                Action::make('archive')->label('Archiver')->icon(Heroicon::OutlinedArchiveBox)
                    ->requiresConfirmation()
                    ->visible(fn (Site $record): bool => ! $record->isArchived() && (auth()->user()?->can('archive', $record) ?? false))
                    ->action(function (Site $record, Action $action): void {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            app(SiteService::class)->archive($record, $actor);

                            Notification::make()->success()->title('Chantier archivé.')->send();
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),
                Action::make('edit')->label('Modifier')->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Site $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Site $record): bool => ! $record->isArchived() && (auth()->user()?->can('update', $record) ?? false)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }
}
