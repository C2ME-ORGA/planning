<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;

/**
 * Affiche les chevauchements détectés sans jamais présenter l'enregistrement
 * comme bloqué : la règle métier reste non bloquante et tracée dans l'audit.
 */
final class PlanningWarningAlert
{
    /**
     * @param  array<int, array{message: string}>  $warnings
     */
    public static function notify(
        array $warnings,
        string $savedTitle,
        string $warningTitle = 'Enregistré avec chevauchement',
    ): void {
        if ($warnings === []) {
            Notification::make()->success()->title($savedTitle)->send();

            return;
        }

        $uniqueMessages = collect($warnings)->pluck('message')->unique();

        $messages = $uniqueMessages->take(5)->implode("\n");
        $remaining = max(0, $uniqueMessages->count() - 5);

        Notification::make()
            ->warning()
            ->title($warningTitle)
            ->body($messages.($remaining > 0 ? "\nEt ".$remaining.' autre(s) chevauchement(s).' : ''))
            ->persistent()
            ->send();
    }
}
