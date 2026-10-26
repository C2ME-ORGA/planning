<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Équipe opérationnelle. L'équipe ne porte pas de `leader_id` écrasable :
 * la responsabilité principale est historisée dans `team_leaderships`.
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * @return HasMany<TeamAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TeamAssignment::class)->orderBy('start_date');
    }

    /**
     * @return HasMany<TeamLeadership, $this>
     */
    public function leaderships(): HasMany
    {
        return $this->hasMany(TeamLeadership::class)->orderBy('start_date');
    }

    public function currentLeadership(?Carbon $on = null): ?TeamLeadership
    {
        $date = ($on ?? Carbon::today())->toDateString();

        return $this->leaderships()
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', $date)
            ->where(static function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('start_date')
            ->first();
    }
}
