<?php

namespace App\Filament\Resources\Teams\RelationManagers;

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

class TeamAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Affectations';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Salarié')
                    ->state(fn (TeamAssignment $record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('kind')
                    ->label('Nature')
                    ->badge()
                    ->formatStateUsing(fn (TeamAssignmentKind $state): string => $state->label()),

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
                Action::make('assignEmployee')
                    ->label('Affecter un salarié')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Ajouter une affectation à l’équipe')
                    ->modalDescription(
                        'Une affectation temporaire ne modifie pas l’équipe principale du salarié. '
                        .'L’affectation doit être couverte par une période d’emploi.',
                    )
                    ->schema([
                        Select::make('employee_id')
                            ->label('Salarié')
                            ->options(fn (): array => Employee::query()
                                ->orderBy('last_name')
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (Employee $employee): array => [
                                    $employee->id => $employee->fullName(),
                                ])
                                ->all())
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
                            /** @var Team $team */
                            $team = $this->getOwnerRecord();
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var Employee $employee */
                            $employee = Employee::query()->findOrFail($data['employee_id']);

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
