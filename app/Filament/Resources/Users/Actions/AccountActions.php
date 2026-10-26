<?php

namespace App\Filament\Resources\Users\Actions;

use App\Models\User;
use App\Services\Accounts\AccountActivationService;
use App\Services\Accounts\AccountAdministration;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Actions de gestion des comptes, partagées entre la liste et la fiche.
 *
 * Chaque action vérifie explicitement la policy : Filament n'applique pas
 * automatiquement les policies aux actions personnalisées.
 */
class AccountActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Désactiver')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Désactiver le compte')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif')
                    ->helperText('Au moins 10 caractères. Le motif est conservé dans le journal d’audit.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => auth()->user()?->can('deactivate', $record) ?? false)
            ->action(function (User $record, array $data): void {
                app(AccountAdministration::class)->deactivate(
                    $record,
                    $data['reason'],
                    auth()->user(),
                );

                Notification::make()->success()->title('Compte désactivé et sessions révoquées.')->send();
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Réactiver')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Réactiver le compte')
            ->schema([
                Textarea::make('reason')
                    ->label('Motif')
                    ->helperText('Au moins 10 caractères. Le motif est conservé dans le journal d’audit.')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->visible(fn (User $record): bool => auth()->user()?->can('reactivate', $record) ?? false)
            ->action(function (User $record, array $data): void {
                app(AccountAdministration::class)->reactivate(
                    $record,
                    $data['reason'],
                    auth()->user(),
                );

                Notification::make()->success()->title('Compte réactivé.')->send();
            });
    }

    public static function resendActivation(): Action
    {
        return Action::make('resendActivation')
            ->label('Renvoyer l’activation')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => $record->activated_at === null
                && (auth()->user()?->can('resendActivation', $record) ?? false))
            ->action(function (User $record): void {
                app(AccountActivationService::class)->send($record, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Nouveau lien d’activation envoyé.')
                    ->send();
            });
    }
}
