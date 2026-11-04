<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Filament\Support\ActionFeedback;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ClientActions
{
    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('Archiver')
            ->icon('heroicon-o-archive-box')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Archiver le client')
            ->modalDescription(
                'L’archivage est refusé si un chantier du client n’est ni terminé ni annulé. '
                .'Les chantiers ne sont pas archivés automatiquement.',
            )
            ->visible(fn (Client $record): bool => auth()->user()?->can('archive', $record) ?? false)
            ->action(function (Client $record, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(ClientService::class)->archive($record, $actor);

                    Notification::make()->success()->title('Client archivé.')->send();
                } catch (ValidationException $exception) {
                    ActionFeedback::validationFailure($action, $exception);
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restaurer')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Restaurer le client')
            ->modalDescription('Seul le client est restauré ; ses chantiers restent inchangés.')
            ->visible(fn (Client $record): bool => auth()->user()?->can('restore', $record) ?? false)
            ->action(function (Client $record, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(ClientService::class)->restore($record, $actor);

                    Notification::make()->success()->title('Client restauré.')->send();
                } catch (ValidationException $exception) {
                    ActionFeedback::validationFailure($action, $exception);
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }
}
