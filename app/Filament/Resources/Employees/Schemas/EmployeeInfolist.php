<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\Employee;
use App\Services\Employees\EmployeeLifecycleService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identité')
                    ->schema([
                        TextEntry::make('full_name')
                            ->label('Nom complet')
                            ->state(fn (Employee $record): string => $record->fullName()),

                        TextEntry::make('user.email')
                            ->label('Compte applicatif')
                            ->placeholder('Aucun compte lié'),

                        TextEntry::make('account_status')
                            ->label('Statut du compte')
                            ->badge()
                            ->state(fn (Employee $record): string => match (true) {
                                $record->user === null => 'Sans compte',
                                $record->user->isActive() => 'Compte actif',
                                default => 'Compte désactivé',
                            })
                            ->color(fn (Employee $record): string => match (true) {
                                $record->user === null => 'gray',
                                $record->user->isActive() => 'success',
                                default => 'danger',
                            }),
                    ])
                    ->columns(3),

                Section::make('Emploi')
                    ->schema([
                        TextEntry::make('employment_status')
                            ->label('Situation')
                            ->badge()
                            ->state(fn (Employee $record): string => $record->isEmployedAt() ? 'En emploi' : 'Hors emploi')
                            ->color(fn (Employee $record): string => $record->isEmployedAt() ? 'success' : 'gray'),

                        TextEntry::make('current_period')
                            ->label('Période d’emploi en cours')
                            ->state(function (Employee $record): string {
                                $period = $record->currentEmploymentPeriod();

                                if ($period === null) {
                                    return 'Aucune période en cours';
                                }

                                return $period->end_date === null
                                    ? 'Depuis le '.$period->start_date->format('d/m/Y').' (ouverte)'
                                    : 'Du '.$period->start_date->format('d/m/Y').' au '.$period->end_date->format('d/m/Y');
                            }),

                        TextEntry::make('period_count')
                            ->label('Périodes enregistrées')
                            ->state(fn (Employee $record): int => $record->employmentPeriods()->count()),
                    ])
                    ->columns(3),

                Section::make('Équipe')
                    ->schema([
                        TextEntry::make('primary_team')
                            ->label('Équipe principale actuelle')
                            ->state(fn (Employee $record): string => $record->currentPrimaryAssignment()?->team->name ?? 'Aucune'),

                        TextEntry::make('current_leadership')
                            ->label('Responsabilité principale')
                            ->state(function (Employee $record): string {
                                $leadership = $record->currentLeadership();

                                return $leadership === null
                                    ? 'Aucune'
                                    : $leadership->team?->name.' depuis le '.$leadership->start_date->format('d/m/Y');
                            }),
                    ])
                    ->columns(2),

                Section::make('Points à corriger avant une sortie')
                    ->description(
                        'Ces affectations ou responsabilités resteraient actives après la date du jour. '
                        .'Elles doivent être clôturées ou annulées explicitement avant la sortie.',
                    )
                    ->schema([
                        TextEntry::make('exit_warnings')
                            ->hiddenLabel()
                            ->html()
                            ->state(function (Employee $record): string {
                                $blockers = app(EmployeeLifecycleService::class)
                                    ->exitBlockers($record, Carbon::today()->toDateString());

                                if ($blockers === []) {
                                    return 'Aucun blocage à la date du jour.';
                                }

                                return '<ul><li>'
                                    .implode('</li><li>', array_map(static fn (string $line): string => e($line), $blockers))
                                    .'</li></ul>';
                            })
                            ->color(fn (Employee $record): string => app(EmployeeLifecycleService::class)
                                ->exitBlockers($record, Carbon::today()->toDateString()) === [] ? 'success' : 'danger'),
                    ]),
            ]);
    }
}
