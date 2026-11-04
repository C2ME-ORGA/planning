<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Filament\Resources\Employees\Actions\TeamLeadershipActions;
use App\Filament\Support\ActionFeedback;
use App\Models\Employee;
use App\Models\Team;
use App\Models\TeamLeadership;
use App\Models\User;
use App\Services\Employees\TeamLeadershipService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class TeamLeadershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'leaderships';

    protected static ?string $title = 'Responsables principaux';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Responsable')
                    ->state(fn (TeamLeadership $record): string => $record->employee?->fullName() ?? '—'),

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
                    ->state(fn (TeamLeadership $record): string => match (true) {
                        $record->isCancelled() => 'Annulée',
                        $record->end_date === null => 'Ouverte',
                        default => 'Clôturée',
                    })
                    ->color(fn (TeamLeadership $record): string => match (true) {
                        $record->isCancelled() => 'danger',
                        $record->end_date === null => 'success',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Action::make('assignLeader')
                    ->label('Désigner un responsable')
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading('Désigner le responsable principal')
                    ->modalDescription(
                        'Le salarié doit être couvert par une période d’emploi, disposer d’un compte actif '
                        .'et avoir le rôle Chef d’équipe ou Conducteur de travaux.',
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

                        DatePicker::make('start_date')
                            ->label('Début')
                            ->required()
                            ->default(now()->toDateString()),

                        DatePicker::make('end_date')
                            ->label('Fin (laisser vide si ouverte)'),

                        Textarea::make('reason')
                            ->label('Motif (facultatif)')
                            ->maxLength(500),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('create', TeamLeadership::class) ?? false)
                    ->action(function (array $data, Action $action): void {
                        try {
                            /** @var Team $team */
                            $team = $this->getOwnerRecord();
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var Employee $employee */
                            $employee = Employee::query()->findOrFail($data['employee_id']);

                            app(TeamLeadershipService::class)->assign(
                                $team,
                                $employee,
                                (string) $data['start_date'],
                                filled($data['end_date'] ?? null) ? (string) $data['end_date'] : null,
                                $actor,
                                filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                            );

                            Notification::make()->success()->title('Responsable principal désigné.')->send();
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),

                Action::make('reassignLeader')
                    ->label('Réaffecter')
                    ->icon('heroicon-o-arrow-path')
                    ->modalHeading('Réaffecter la responsabilité principale')
                    ->modalDescription(
                        'L’ancienne responsabilité ouverte est clôturée la veille de la date d’effet. '
                        .'L’historique est conservé.',
                    )
                    ->schema([
                        Select::make('employee_id')
                            ->label('Nouveau responsable')
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

                        DatePicker::make('effective_date')
                            ->label('Date d’effet')
                            ->required()
                            ->default(now()->toDateString()),

                        Textarea::make('reason')
                            ->label('Motif')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->action(function (array $data, Action $action): void {
                        try {
                            /** @var Team $team */
                            $team = $this->getOwnerRecord();
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var Employee $employee */
                            $employee = Employee::query()->findOrFail($data['employee_id']);

                            app(TeamLeadershipService::class)->reassign(
                                $team,
                                $employee,
                                (string) $data['effective_date'],
                                $actor,
                                (string) $data['reason'],
                            );

                            Notification::make()->success()->title('Responsabilité réaffectée.')->send();
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),
            ])
            ->recordActions([
                TeamLeadershipActions::close(),
                TeamLeadershipActions::cancel(),
            ]);
    }
}
