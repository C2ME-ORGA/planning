<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
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

    public static function validationFailure(Action $action, ValidationException $exception): never
    {
        $message = collect($exception->errors())->flatten()->first();

        Notification::make()
            ->danger()
            ->title('Opération refusée')
            ->body(is_string($message) ? $message : $exception->getMessage())
            ->persistent()
            ->send();

        $action->halt();

        throw $exception;
    }
}
