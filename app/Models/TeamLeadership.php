<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TeamLeadershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Responsabilité principale d'une équipe, historisée et datée.
 *
 * Une équipe ne peut avoir qu'une responsabilité principale à la fois. La
 * responsabilité est volontairement séparée de l'affectation d'équipe : elle
 * exige un compte actif et un rôle compatible pendant toute la période.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'employee_id', 'start_date', 'end_date'])]
class TeamLeadership extends Model
{
    /** @use HasFactory<TeamLeadershipFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isOpen(): bool
    {
        return $this->end_date === null && $this->cancelled_at === null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isNotEndedAt(CarbonInterface $date): bool
    {
        return $this->cancelled_at === null
            && ($this->end_date === null || $this->end_date->gte($date));
    }
}
