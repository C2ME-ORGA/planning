<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Support\ActionFeedback;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use App\Services\Employees\EmploymentPeriodService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class EmploymentPeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'employmentPeriods';

    protected static ?string $title = 'Périodes d’emploi';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('start_date')
                    ->label('Début')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->placeholder('Ouverte'),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->state(fn (EmploymentPeriod $record): string => $record->isOpen() ? 'Ouverte' : 'Clôturée')
                    ->color(fn (EmploymentPeriod $record): string => $record->isOpen() ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('addPeriod')
                    ->label('Ajouter une période / réembauche')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Ajouter une période d’emploi')
                    ->modalDescription(
                        'Une réembauche crée une nouvelle période. La période précédente n’est jamais réécrite. '
                        .'Les dates sont inclusives et ne peuvent pas se chevaucher.',
                    )
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Début')
                            ->required()
                            ->default(now()->toDateString()),

                        DatePicker::make('end_date')
                            ->label('Fin (laisser vide si ouverte)'),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('create', EmploymentPeriod::class) ?? false)
                    ->action(function (array $data, Action $action): void {
                        try {
                            /** @var Employee $employee */
                            $employee = $this->getOwnerRecord();
                            /** @var User $actor */
                            $actor = auth()->user();

                            app(EmploymentPeriodService::class)->add(
                                $employee,
                                (string) $data['start_date'],
                                filled($data['end_date'] ?? null) ? (string) $data['end_date'] : null,
                                $actor,
                            );

                            Notification::make()->success()->title('Période d’emploi ajoutée.')->send();
                        } catch (Throwable $exception) {
                            ActionFeedback::failure($action, $exception);
                        }
                    }),
            ]);
    }
}
