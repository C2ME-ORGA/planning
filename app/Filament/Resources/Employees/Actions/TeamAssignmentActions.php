<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Support\ActionFeedback;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Employees\TeamAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

class TeamAssignmentActions
{
    public static function close(): Action
    {
        return Action::make('closeAssignment')
            ->label('Clôturer')
            ->icon('heroicon-o-check-circle')
            ->modalHeading('Clôturer l’affectation')
            ->schema([
                DatePicker::make('end_date')
                    ->label('Dernier jour de l’affectation')
                    ->required()
                    ->default(now()->toDateString()),
            ])
            ->visible(fn (TeamAssignment $record): bool => ! $record->isCancelled()
                && $record->end_date === null
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (TeamAssignment $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(TeamAssignmentService::class)->close(
                        $record,
                        (string) $data['end_date'],
                        $actor,
                    );

                    Notification::make()->success()->title('Affectation clôturée.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelAssignment')
            ->label('Annuler (future)')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Annuler une affectation future')
            ->modalDescription('L’affectation reste dans l’historique avec son motif d’annulation.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->visible(fn (TeamAssignment $record): bool => ! $record->isCancelled()
                && $record->start_date->isAfter(now()->startOfDay())
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (TeamAssignment $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(TeamAssignmentService::class)->cancel(
                        $record,
                        (string) $data['reason'],
                        $actor,
                    );

                    Notification::make()->success()->title('Affectation annulée et conservée dans l’historique.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }
}
