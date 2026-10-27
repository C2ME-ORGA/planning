<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $company_name
 * @property string|null $contact_name
 * @property string|null $address
 * @property string|null $street
 * @property string|null $address_complement
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $archived_at
 */
#[Fillable([
    'company_name',
    'contact_name',
    'address',
    'street',
    'address_complement',
    'postal_code',
    'city',
    'country',
    'phone',
    'email',
])]
class Client extends Model
{
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new LogicException('Un client ne peut pas être supprimé ; utilisez l’archivage.');
        });
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Adresse affichable. Les clients créés avant l'adresse structurée
     * conservent leur champ libre `address` ; aucune découpe automatique n'est
     * tentée.
     */
    public function fullAddress(): string
    {
        $postalAndCity = trim(trim((string) $this->postal_code).' '.trim((string) $this->city));

        $parts = array_values(array_filter([
            trim((string) $this->street),
            trim((string) $this->address_complement),
            $postalAndCity,
            trim((string) $this->country),
        ], static fn (string $part): bool => $part !== ''));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return trim((string) $this->address);
    }
}
