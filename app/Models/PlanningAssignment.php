<?php

namespace App\Models;

use App\Enums\PlanningHalfDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Affectation d'une équipe à un chantier sur une demi-journée.
 *
 * L'équipe n'est pas recopiée : ses membres sont déduits des affectations
 * d'équipe historisées à la date planifiée. Les exclusions individuelles sont
 * portées par `planning_assignment_exclusions` et ne créent ni absence ni temps
 * réellement travaillé.
 *
 * @property int $id
 * @property int $site_id
 * @property int $team_id
 * @property CarbonImmutable $work_date
 * @property PlanningHalfDay $half_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['site_id', 'team_id', 'work_date', 'half_day'])]
class PlanningAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'half_day' => PlanningHalfDay::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new LogicException(
                'Une affectation de planning ne peut pas être supprimée ; son historique doit être conservé.',
            );
        });
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<PlanningAssignmentExclusion, $this>
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(PlanningAssignmentExclusion::class);
    }

    /**
     * @return array<int, int>
     */
    public function excludedEmployeeIds(): array
    {
        return $this->exclusions()
            ->orderBy('employee_id')
            ->pluck('employee_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
