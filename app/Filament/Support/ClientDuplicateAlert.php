<?php

namespace App\Filament\Support;

use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientService;
use Filament\Notifications\Notification;

/**
 * Alerte informative de doublon après enregistrement : elle ne bloque jamais
 * la création ou la modification. La détection est exécutée côté serveur.
 */
final class ClientDuplicateAlert
{
    public static function notify(Client $client, User $actor): void
    {
        // L'alerte est informative : elle ne doit jamais faire échouer une
        // création déjà enregistrée pour un acteur sans droit de consultation.
        if (! $actor->can('viewAny', Client::class)) {
            return;
        }

        $duplicates = app(ClientService::class)->duplicatesFor($client, $actor);

        if ($duplicates->isEmpty()) {
            return;
        }

        $names = $duplicates->take(5)->pluck('company_name')->implode(', ');
        $remaining = $duplicates->count() - 5;

        Notification::make()
            ->warning()
            ->title('Doublon potentiel détecté')
            ->body(sprintf(
                'Le client a bien été enregistré. Autre(s) client(s) proche(s) : %s%s.',
                $names,
                $remaining > 0 ? sprintf(', et %d autre(s)', $remaining) : '',
            ))
            ->persistent()
            ->send();
    }
}
