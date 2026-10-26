<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Nom affiché'),
                TextEntry::make('email')->label('Adresse e-mail'),
                TextEntry::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label()),
                TextEntry::make('is_active')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Actif' : 'Désactivé')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextEntry::make('activated_at')
                    ->label('Activé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Non activé'),
                TextEntry::make('last_login_at')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais'),
                TextEntry::make('app_authentication_secret')
                    ->label('Double authentification')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Configurée' : 'Non configurée'),
                TextEntry::make('deactivation_reason')
                    ->label('Motif de désactivation')
                    ->placeholder('—'),
            ]);
    }
}
