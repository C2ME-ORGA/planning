<?php

namespace App\Filament\Resources\Sites\Actions;

use App\Enums\SiteStatus;
use App\Filament\Support\ActionFeedback;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SiteActions
{
    public static function transition(): Action
    {
        return Action::make('transition')
            ->label('Changer le statut')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Site $record): bool => auth()->user()?->can('transition', $record) ?? false)
            ->schema([
                Select::make('target')
                    ->label('Nouveau statut')
                    ->options(fn (Site $record): array => self::options(
                        $record->statusEnum()?->allowedTransitions() ?? [],
                    ))
                    ->required(),
                Textarea::make('reason')
                    ->label('Motif')
                    ->maxLength(500)
                    ->required(fn (Site $record, Get $get): bool => $record->statusEnum() === SiteStatus::EnCours
                        && $get('target') === SiteStatus::Annule->value)
                    ->helperText('Obligatoire pour annuler un chantier en cours.'),
            ])
            ->action(function (Site $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $target = SiteStatus::tryFrom((string) ($data['target'] ?? ''));

                    if ($target === null) {
                        throw ValidationException::withMessages(['target' => 'Statut cible invalide.']);
                    }

                    app(SiteService::class)->transition(
                        $record,
                        $target,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                        $actor,
                    );

                    Notification::make()->success()->title('Statut du chantier modifié.')->send();
                } catch (ValidationException $exception) {
                    ActionFeedback::validationFailure($action, $exception);
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Réouvrir')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (Site $record): bool => auth()->user()?->can('reopen', $record) ?? false)
            ->schema([
                Select::make('target')
                    ->label('Statut opérationnel cible')
                    ->options(fn (Site $record): array => self::options(
                        $record->statusEnum()?->reopenTargets() ?? [],
                    ))
                    ->required(),
                Textarea::make('reason')
                    ->label('Motif de la réouverture')
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (Site $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $target = SiteStatus::tryFrom((string) ($data['target'] ?? ''));

                    if ($target === null) {
                        throw ValidationException::withMessages(['target' => 'Statut cible invalide.']);
                    }

                    app(SiteService::class)->reopen(
                        $record,
                        $target,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                        $actor,
                    );

                    Notification::make()->success()->title('Chantier réouvert.')->send();
                } catch (ValidationException $exception) {
                    ActionFeedback::validationFailure($action, $exception);
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    /**
     * @param  array<int, SiteStatus>  $statuses
     * @return array<string, string>
     */
    private static function options(array $statuses): array
    {
        $options = [];

        foreach ($statuses as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
