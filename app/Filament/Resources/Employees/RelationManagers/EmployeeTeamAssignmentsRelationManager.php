<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Enums\TeamAssignmentKind;
use App\Filament\Resources\Employees\Actions\TeamAssignmentActions;
use App\Filament\Support\ActionFeedback;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Employees\TeamAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class EmployeeTeamAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'teamAssignments';

    protected static ?string $title = 'Affectations d’équipe';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('kind')
                    ->label('Nature')
                    ->badge()
                    ->formatStateUsing(fn (TeamAssignmentKind $state): string => $state->label()),

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
                    ->state(fn (TeamAssignment $record): string => match (true) {
                        $record->isCancelled() => 'Annulée',
                        $record->end_date === null => 'Ouverte',
                        default => 'Clôturée',
                    })
                    ->color(fn (TeamAssignment $record): string => match (true) {
                        $record->isCancelled() => 'danger',
                        $record->end_date === null => 'success',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Action::make('assignTeam')
                    ->label('Ajouter une affectation')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Affecter le salarié à une équipe')
                    ->modalDescription(
                        'Une affectation temporaire est un enregistrement distinct : elle ne modifie jamais '
                        .'l’équipe principale. L’affectation doit être couverte par une période d’emploi.',
                    )
                    ->schema([
                        Select::make('team_id')
                            ->label('Équipe')
                            ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),

                        Select::make('kind')
                            ->label('Nature')
                            ->options(TeamAssignmentKind::options())
                            ->default(TeamAssignmentKind::Temporary->value)
                            ->required()
                            ->native(false),

                        DatePicker::make('start_date')
                            ->label('Début')
                            ->required()
                            ->default(now()->toDateString()),

                        DatePicker::make('end_date')
                            ->label('Fin (laisser vide si ouverte)'),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('create', TeamAssignment::class) ?? false)
                    ->action(function (array $data, Action $action): void {
                        try {
                            /** @var Employee $employee */
                            $employee = $this->getOwnerRecord();
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var Team $team */
                            $team = Team::query()->findOrFail($data['team_id']);

                            app(TeamAssignmentService::class)->assign(
                                $employee,
                                $team,
                                TeamAssignmentKind::from((string) $data['kind']),
                                (string) $data['start_date'],
                                filled($data['end_date'] ?? null) ? (string) $data['end_date'] : null,
                                $actor,
                            );

                            Notification::make()->success()->title('Affectation ajoutée.')->send();
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),
            ])
            ->recordActions([
                TeamAssignmentActions::close(),
                TeamAssignmentActions::cancel(),
            ]);
    }
}
