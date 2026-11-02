<?php

namespace App\Filament\Resources\AuditEvents;

use App\Enums\AuditAction;
use App\Enums\Role;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sequence')->label('#'),
                TextEntry::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('actor.email')->label('Auteur')->placeholder('Système'),
                TextEntry::make('actor_role')
                    ->label('Rôle')
                    ->formatStateUsing(fn (?string $state): string => Role::tryFrom((string) $state)?->label() ?? '—'),
                TextEntry::make('action')
                    ->label('Action')
                    ->formatStateUsing(fn (AuditAction $state): string => $state->label()),
                TextEntry::make('subject_type')
                    ->label('Objet')
                    ->placeholder('—'),
                TextEntry::make('subject_id')->label('Identifiant')->placeholder('—'),
                TextEntry::make('reason')
                    ->label('Motif')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('changes')
                    ->label('Ancien / nouveau')
                    ->formatStateUsing(fn (?array $state): string => $state === null
                        ? '—'
                        : (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs'])
                    ->columnSpanFull(),
                TextEntry::make('context')
                    ->label('Contexte technique')
                    ->formatStateUsing(fn (?array $state): string => $state === null
                        ? '—'
                        : (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs'])
                    ->columnSpanFull(),
                TextEntry::make('hash')->label('Signature')->copyable()->columnSpanFull(),
                TextEntry::make('previous_hash')->label('Signature précédente')->placeholder('—')->copyable()->columnSpanFull(),
            ]);
    }
}
