<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stocke un instant en UTC dans MySQL et le restitue comme instant UTC.
 *
 * Les dates de l'application sont interprétées en `Europe/Paris`, mais les
 * instants des segments doivent rester non ambigus. La conversion est donc
 * explicite dans les deux sens et ne dépend pas du fuseau par défaut de PHP.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|string>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, 'UTC');

        if (! $date instanceof CarbonImmutable) {
            throw new InvalidArgumentException('La valeur stockée n’est pas un instant UTC valide.');
        }

        return $date->utc();
    }

    /**
     * Un instant doit être fourni comme objet temporel explicite. Toute chaîne
     * de date-heure est refusée, y compris lorsqu'elle porte un offset : seule
     * une valeur `DateTimeInterface` permet de garantir un instant non ambigu.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException(
                'Un instant doit être fourni comme DateTimeInterface ; toute chaîne de date-heure est refusée.',
            );
        }

        return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s');
    }
}
