<?php

use App\Exceptions\BusinessRuleException;
use App\Filament\Support\ActionFeedback;
use Illuminate\Database\QueryException;

it('shows an explicitly user-facing business refusal', function () {
    $message = ActionFeedback::message(
        new BusinessRuleException('Le dernier compte Bureau / Direction actif ne peut pas être retiré.'),
    );

    expect($message)->toBe('Le dernier compte Bureau / Direction actif ne peut pas être retiré.');
});

it('masks an unmarked runtime exception as technical', function () {
    $message = ActionFeedback::message(
        new RuntimeException('Détail interne qui ne doit pas être affiché.'),
    );

    expect($message)->toContain('erreur technique')
        ->and($message)->not->toContain('Détail interne');
});

it('never exposes a SQL exception to the user', function () {
    $exception = new QueryException(
        'mysql',
        'select * from users where email = ?',
        ['secret@example.test'],
        new PDOException('SQLSTATE[42S02]: Base table or view not found'),
    );

    $message = ActionFeedback::message($exception);

    expect($message)->toContain('erreur technique')
        ->and($message)->not->toContain('SQLSTATE')
        ->and($message)->not->toContain('select')
        ->and($message)->not->toContain('users');
});

it('hides raw PDO and programming errors behind a generic message', function (string $class) {
    $exception = match ($class) {
        PDOException::class => new PDOException('SQLSTATE[HY000]: General error'),
        TypeError::class => new TypeError('Argument interne invalide.'),
        default => new Error('Erreur interne.'),
    };

    $message = ActionFeedback::message($exception);

    expect($message)->toContain('erreur technique')
        ->and($message)->not->toContain('SQLSTATE')
        ->and($message)->not->toContain('Argument interne')
        ->and($message)->not->toContain('Erreur interne');
})->with([PDOException::class, TypeError::class, Error::class]);

it('falls back to a generic message for an empty business message', function () {
    expect(ActionFeedback::message(new BusinessRuleException('   ')))
        ->toContain('erreur technique');
});
