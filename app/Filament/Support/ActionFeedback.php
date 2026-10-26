<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Affiche un refus métier de façon actionnable, sans jamais présenter
 * l'opération comme réussie. Les règles restent imposées par les services.
 */
final class ActionFeedback
{
    public static function failure(Action $action, Throwable $exception): never
    {
        Notification::make()
            ->danger()
            ->title('Opération refusée')
            ->body($exception->getMessage())
            ->persistent()
            ->send();

        $action->halt();

        throw $exception;
    }
}
