<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string $address
 * @property int $client_id
 * @property string $status
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'address', 'client_id', 'status', 'archived_at'])]
class Site extends Model
{
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new LogicException('Un chantier ne peut pas être supprimé ; utilisez l’archivage.');
        });
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function statusEnum(): ?SiteStatus
    {
        return SiteStatus::tryFrom((string) $this->status);
    }

    public function statusLabel(): string
    {
        return $this->statusEnum()?->label() ?? (string) $this->status;
    }
}
