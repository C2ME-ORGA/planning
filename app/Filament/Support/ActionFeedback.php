<?php

namespace App\Filament\Support;

use App\Exceptions\UserFacingException;
use Error;
use ErrorException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * Affiche un refus métier de façon actionnable, sans jamais présenter
 * l'opération comme réussie ni divulguer un détail technique.
 *
 * Les services métier expriment leurs refus avec des messages français
 * destinés à l'utilisateur. Les erreurs de stockage, de type ou de framework
 * sont journalisées et remplacées par un message générique.
 */
final class ActionFeedback
{
    private const GENERIC_MESSAGE = 'Une erreur technique est survenue. L’opération n’a pas été effectuée.';

    public static function failure(Action $action, Throwable $exception): never
    {
        if ($exception instanceof ValidationException) {
            self::validationFailure($action, $exception);
        }

        Notification::make()
            ->danger()
            ->title('Opération refusée')
            ->body(self::message($exception))
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
            ->body(is_string($message) && trim($message) !== '' ? $message : self::GENERIC_MESSAGE)
            ->persistent()
            ->send();

        $action->halt();

        throw $exception;
    }

    /**
     * Retourne un message sûr pour l'interface.
     *
     * Une exception technique est journalisée et ne laisse jamais filtrer son
     * message SQL, son chemin de fichier ou sa trace.
     */
    public static function message(Throwable $exception): string
    {
        if (self::isTechnical($exception)) {
            report($exception);

            return self::GENERIC_MESSAGE;
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            report($exception);

            return self::GENERIC_MESSAGE;
        }

        return $message;
    }

    private static function isTechnical(Throwable $exception): bool
    {
        if ($exception instanceof QueryException
            || $exception instanceof PDOException
            || $exception instanceof ErrorException
            || $exception instanceof Error) {
            return true;
        }

        // Seuls les refus explicitement marqués comme destinés à l'utilisateur
        // (ou les refus d'autorisation et de validation) peuvent afficher leur
        // message. Une RuntimeException non marquée est traitée comme interne :
        // c'est le cas par défaut, y compris pour les erreurs de framework.
        return ! ($exception instanceof UserFacingException)
            && ! ($exception instanceof AuthorizationException)
            && ! ($exception instanceof ValidationException);
    }
}
