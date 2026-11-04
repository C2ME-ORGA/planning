<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Exceptions\EmployeeExitBlockedException;
use App\Filament\Support\ActionFeedback;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeLifecycleService;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\EmploymentPeriodService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

class EmployeeActions
{
    public static function linkAccount(): Action
    {
        return Action::make('linkAccount')
            ->label('Lier un compte')
            ->icon('heroicon-o-link')
            ->modalHeading('Lier un compte applicatif')
            ->schema([
                Select::make('user_id')
                    ->label('Compte')
                    ->options(fn (): array => User::query()
                        ->whereNotIn('id', Employee::query()->whereNotNull('user_id')->pluck('user_id'))
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [
                            $user->id => $user->name.' ('.$user->email.')',
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (Employee $record): bool => $record->user_id === null
                && (auth()->user()?->can('linkAccount', $record) ?? false))
            ->action(function (Employee $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var User $user */
                    $user = User::query()->findOrFail($data['user_id']);

                    app(EmployeeService::class)->linkAccount($record, $user, $actor);

                    Notification::make()->success()->title('Compte lié au salarié.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function unlinkAccount(): Action
    {
        return Action::make('unlinkAccount')
            ->label('Délier le compte')
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Délier le compte applicatif')
            ->modalDescription('La fiche salarié et son historique sont conservés.')
            ->visible(fn (Employee $record): bool => $record->user_id !== null
                && (auth()->user()?->can('unlinkAccount', $record) ?? false))
            ->action(function (Employee $record, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(EmployeeService::class)->unlinkAccount($record, $actor);

                    Notification::make()->success()->title('Compte délié du salarié.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function addEmploymentPeriod(): Action
    {
        return Action::make('addEmploymentPeriod')
            ->label('Ajouter une période / réembauche')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Ajouter une période d’emploi')
            ->modalDescription(
                'Une réembauche crée une nouvelle période : la période précédente n’est jamais réécrite. '
                .'Les dates sont inclusives.',
            )
            ->schema([
                DatePicker::make('start_date')
                    ->label('Début')
                    ->required()
                    ->default(now()->toDateString()),

                DatePicker::make('end_date')
                    ->label('Fin (laisser vide si la période est ouverte)'),
            ])
            ->visible(fn (Employee $record): bool => auth()->user()?->can('addEmploymentPeriod', $record) ?? false)
            ->action(function (Employee $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(EmploymentPeriodService::class)->add(
                        $record,
                        (string) $data['start_date'],
                        filled($data['end_date'] ?? null) ? (string) $data['end_date'] : null,
                        $actor,
                    );

                    Notification::make()->success()->title('Période d’emploi ajoutée.')->send();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }

    public static function recordExit(): Action
    {
        return Action::make('recordExit')
            ->label('Enregistrer la sortie')
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->modalHeading('Enregistrer la sortie du salarié')
            ->modalDescription(
                'La sortie clôt la période d’emploi ouverte. Elle est refusée si une affectation '
                .'ou une responsabilité resterait active après cette date.',
            )
            ->schema([
                DatePicker::make('exit_date')
                    ->label('Date de sortie')
                    ->required()
                    ->default(now()->toDateString()),

                Toggle::make('deactivate_account')
                    ->label('Désactiver le compte applicatif associé')
                    ->helperText(
                        'Le compte sera désactivé immédiatement et ses sessions révoquées. '
                        .'La fiche salarié et l’historique sont conservés. '
                        .'Ne confirmez que si l’accès doit être retiré.',
                    )
                    ->default(false)
                    ->visible(fn (Employee $record): bool => $record->user?->isActive() ?? false),
            ])
            ->visible(fn (Employee $record): bool => $record->currentEmploymentPeriod() !== null
                && (auth()->user()?->can('recordExit', $record) ?? false))
            ->action(function (Employee $record, array $data, Action $action): void {
                try {
                    /** @var User $actor */
                    $actor = auth()->user();
                    $deactivateAccount = (bool) ($data['deactivate_account'] ?? false);
                    $hadActiveAccount = $record->user?->isActive() ?? false;

                    app(EmployeeLifecycleService::class)->exit(
                        $record,
                        (string) $data['exit_date'],
                        $actor,
                        $deactivateAccount,
                    );

                    Notification::make()
                        ->success()
                        ->title($deactivateAccount && $hadActiveAccount
                            ? 'Sortie enregistrée et compte désactivé.'
                            : 'Sortie enregistrée.')
                        ->send();
                } catch (EmployeeExitBlockedException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Sortie bloquée')
                        ->body('Corrigez ou clôturez explicitement : '.implode(' • ', $exception->blockers))
                        ->persistent()
                        ->send();

                    $action->halt();
                } catch (Throwable $exception) {
                    ActionFeedback::failure($action, $exception);
                }
            });
    }
}
