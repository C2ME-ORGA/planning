<?php

namespace App\Filament\Resources\PlanningAssignments;

use App\Enums\PlanningHalfDay;
use App\Filament\Resources\PlanningAssignments\Pages\CreatePlanningAssignment;
use App\Filament\Resources\PlanningAssignments\Pages\EditPlanningAssignment;
use App\Filament\Resources\PlanningAssignments\Pages\ListPlanningAssignments;
use App\Filament\Support\ActionFeedback;
use App\Filament\Support\PlanningWarningAlert;
use App\Models\PlanningAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Planning\PlanningRoster;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class PlanningAssignmentResource extends Resource
{
    protected static ?string $model = PlanningAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Opérationnel';

    protected static ?string $navigationLabel = 'Planning';

    protected static ?string $modelLabel = 'affectation de planning';

    protected static ?string $pluralModelLabel = 'affectations de planning';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Affectation')->schema([
                Select::make('site_id')
                    ->label('Chantier')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('team_id')
                    ->label('Équipe')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                DatePicker::make('work_date')
                    ->label('Date')
                    ->required()
                    ->default(now()->toDateString())
                    ->live(),

                Select::make('half_day')
                    ->label('Demi-journée')
                    ->options(PlanningHalfDay::options())
                    ->required()
                    ->native(false),

                Select::make('excluded_employee_ids')
                    ->label('Salariés exclus')
                    ->multiple()
                    ->searchable()
                    ->options(function (Get $get, ?PlanningAssignment $record): array {
                        $teamId = $get('team_id');
                        $date = $get('work_date');

                        return app(PlanningRoster::class)->options(
                            is_numeric($teamId) ? (int) $teamId : null,
                            is_string($date) ? $date : null,
                            $record?->excludedEmployeeIds() ?? [],
                        );
                    })
                    ->helperText(
                        'Les membres de l’équipe à cette date sont inclus par défaut. '
                        .'Une exclusion ne crée ni absence ni temps travaillé.',
                    ),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['site', 'team'])->withCount('exclusions'))
            ->defaultSort('work_date', 'desc')
            ->columns([
                TextColumn::make('work_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('half_day')
                    ->label('Demi-journée')
                    ->badge()
                    ->formatStateUsing(fn (PlanningHalfDay $state): string => $state->label())
                    ->color(fn (PlanningAssignment $record): string => $record->half_day->color()),

                TextColumn::make('site.name')
                    ->label('Chantier')
                    ->searchable(),

                TextColumn::make('team.name')
                    ->label('Équipe')
                    ->searchable(),

                TextColumn::make('exclusions_count')
                    ->label('Exclusions')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Chantier')
                    ->relationship('site', 'name')
                    ->searchable(),

                SelectFilter::make('team_id')
                    ->label('Équipe')
                    ->relationship('team', 'name')
                    ->searchable(),

                SelectFilter::make('half_day')
                    ->label('Demi-journée')
                    ->options(PlanningHalfDay::options()),
            ])
            ->recordActions([
                Action::make('checkConflicts')
                    ->label('Vérifier les chevauchements')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->visible(fn (PlanningAssignment $record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->action(function (PlanningAssignment $record, Action $action): void {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            $warnings = app(PlanningAssignmentService::class)->warningsFor($record, $actor);

                            PlanningWarningAlert::notify(
                                $warnings,
                                'Aucun chevauchement détecté.',
                                'Chevauchements détectés',
                            );
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),

                Action::make('edit')
                    ->label('Modifier')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (PlanningAssignment $record): string => static::getUrl('edit', ['record' => $record]))
                    ->visible(fn (PlanningAssignment $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanningAssignments::route('/'),
            'create' => CreatePlanningAssignment::route('/create'),
            'edit' => EditPlanningAssignment::route('/{record}/edit'),
        ];
    }
}
