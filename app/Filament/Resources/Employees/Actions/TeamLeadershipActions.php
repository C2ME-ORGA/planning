<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Filament\Support\ActionFeedback;
use App\Models\TeamLeadership;
use App\Models\User;
use App\Services\Employees\TeamLeadershipService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

class TeamLeadershipActions
{
    public static function close(): Action
    {
        return Action::make('closeLeadership')
            ->label('Clôturer')
            ->icon('heroicon-o-check-circle')
            ->modalHeading('Clôturer la responsabilité')
            ->schema([
                DatePicker::make('end_date')
                    ->label('Dernier jour de responsabilité')
                    ->required()
                    ->default(now()->toDateString()),

                Textarea::make('reason')
                    ->label('Motif (facultatif)')
                    ->maxLength(500),
            ])
            ->visible(fn (TeamLeadership $record): bool => ! $record->isCancelled()
                && $record->end_date === null
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (TeamLeadership $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(TeamLeadershipService::class)->close(
                        $record,
                        (string) $data['end_date'],
                        $actor,
                        filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                    );

                    Notification::make()->success()->title('Responsabilité clôturée.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelLeadership')
            ->label('Annuler (future)')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Annuler une responsabilité future')
            ->modalDescription('La responsabilité reste dans l’historique avec son motif d’annulation.')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->visible(fn (TeamLeadership $record): bool => ! $record->isCancelled()
                && $record->start_date->isAfter(now()->startOfDay())
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (TeamLeadership $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(TeamLeadershipService::class)->cancel(
                        $record,
                        (string) $data['reason'],
                        $actor,
                    );

                    Notification::make()->success()->title('Responsabilité annulée et conservée dans l’historique.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }
}
