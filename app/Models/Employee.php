<?php

namespace App\Models;

use App\Enums\TeamAssignmentKind;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fiche salarié indépendante du compte applicatif.
 *
 * Un salarié peut exister sans compte. Le lien vers `users` est facultatif,
 * unique et supprimé en douceur (`nullOnDelete`) si un compte est réellement
 * supprimé : la fiche et son historique sont conservés.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['first_name', 'last_name'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EmploymentPeriod, $this>
     */
    public function employmentPeriods(): HasMany
    {
        return $this->hasMany(EmploymentPeriod::class)->orderBy('start_date');
    }

    /**
     * @return HasMany<TeamAssignment, $this>
     */
    public function teamAssignments(): HasMany
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

    /**
     * @return HasMany<Timesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class)->orderBy('period_start');
    }

    public function currentEmploymentPeriod(?CarbonInterface $on = null): ?EmploymentPeriod
    {
        $date = ($on ?? Carbon::today())->toDateString();

        return $this->employmentPeriods()
            ->where('start_date', '<=', $date)
            ->where(static function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('start_date')
            ->first();
    }

    public function isEmployedAt(?CarbonInterface $on = null): bool
    {
        return $this->currentEmploymentPeriod($on) !== null;
    }

    public function currentPrimaryAssignment(?CarbonInterface $on = null): ?TeamAssignment
    {
        $date = ($on ?? Carbon::today())->toDateString();

        return $this->teamAssignments()
            ->where('kind', TeamAssignmentKind::Primary->value)
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', $date)
            ->where(static function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('start_date')
            ->first();
    }

    public function currentLeadership(?CarbonInterface $on = null): ?TeamLeadership
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

    /**
     * Responsabilités non terminées à la date indiquée (y compris celles qui
     * commencent plus tard). Elles empêchent le retrait du rôle applicatif.
     *
     * @return Collection<int, TeamLeadership>
     */
    public function responsibilitiesNotEndedAt(?CarbonInterface $on = null): Collection
    {
        $date = ($on ?? Carbon::today())->toDateString();

        return $this->leaderships()
            ->whereNull('cancelled_at')
            ->where(static function ($query) use ($date): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with('team')
            ->orderBy('start_date')
            ->get();
    }
}
