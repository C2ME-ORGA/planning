<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation souple d'un numéro de téléphone français ou international.
 *
 * Le format exact n'est pas imposé : les séparateurs usuels (espaces,
 * parenthèses, points, tirets, barre oblique) et le préfixe international `+`
 * ou `00` sont acceptés. Seules les valeurs clairement invalides (lettres,
 * longueur incohérente) sont refusées.
 */
final class ValidPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Le numéro de téléphone doit être une chaîne de caractères.');

            return;
        }

        $number = trim($value);

        if ($number === '') {
            $fail('Le numéro de téléphone ne peut pas être vide.');

            return;
        }

        if (preg_match('/^\+?[0-9](?:[0-9\s().\/-]*[0-9])?$/', $number) !== 1) {
            $fail('Le numéro de téléphone contient des caractères non autorisés.');

            return;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (mb_strlen($digits) < 6 || mb_strlen($digits) > 20) {
            $fail('Le numéro de téléphone doit contenir entre 6 et 20 chiffres.');
        }
    }
}
