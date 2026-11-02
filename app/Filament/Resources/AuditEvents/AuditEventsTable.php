<?php

namespace App\Filament\Resources\AuditEvents;

use App\Enums\AuditAction;
use App\Enums\Role;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sequence', 'desc')
            ->columns([
                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('occurred_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('actor.email')
                    ->label('Auteur')
                    ->placeholder('Système'),

                TextColumn::make('actor_role')
                    ->label('Rôle')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Role::tryFrom((string) $state)?->label() ?? '—'),

                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (AuditAction $state): string => $state->label())
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label('Objet')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::afterLast($state, '\\')),

                TextColumn::make('subject_id')
                    ->label('Identifiant')
                    ->placeholder('—'),

                TextColumn::make('reason')
                    ->label('Motif')
                    ->limit(60)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(collect(AuditAction::cases())
                        ->mapWithKeys(fn (AuditAction $action): array => [$action->value => $action->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
