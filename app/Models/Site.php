<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'address', 'client_id', 'status', 'archived_at'])]
class Site extends Model
{
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
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
}
