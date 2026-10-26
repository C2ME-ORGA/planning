<?php

namespace App\Models;

use App\Enums\TeamAssignmentKind;
use Database\Factories\TeamAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Affectation historisée d'un salarié à une équipe.
 *
 * `kind = primary` représente l'équipe principale ; `kind = temporary`
 * représente une affectation temporaire, qui ne modifie jamais l'équipe
 * principale. Les dates sont inclusives et une date de fin nulle signifie que
 * l'affectation est ouverte.
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property TeamAssignmentKind $kind
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'employee_id', 'kind', 'start_date', 'end_date'])]
class TeamAssignment extends Model
{
    /** @use HasFactory<TeamAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TeamAssignmentKind::class,
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

    public function isPrimary(): bool
    {
        return $this->kind === TeamAssignmentKind::Primary;
    }
}
