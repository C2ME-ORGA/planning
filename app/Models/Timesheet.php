<?php

namespace App\Models;

use App\Enums\TimesheetStatus;
use App\Support\BusinessTime;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\WeekDay;
use Database\Factories\TimesheetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Relevé d'heures d'un salarié sur une semaine civile (lundi à dimanche).
 *
 * Le relevé ne contient aucun horaire attendu et ne lit jamais le planning :
 * les heures réelles sont saisies séparément dans `timesheet_segments`.
 *
 * @property int $id
 * @property int $employee_id
 * @property TimesheetStatus $status
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['employee_id', 'status', 'period_start', 'period_end'])]
class Timesheet extends Model
{
    /** @use HasFactory<TimesheetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TimesheetStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Timesheet $timesheet): void {
            $start = $timesheet->period_start;
            $end = $timesheet->period_end;

            if ($start === null || $end === null) {
                return;
            }

            if ((int) $start->isoWeekday() !== WeekDay::Monday->value) {
                throw new LogicException('Un relevé doit commencer un lundi.');
            }

            if (! $start->addDays(6)->isSameDay($end)) {
                throw new LogicException('Un relevé doit couvrir exactement une semaine civile, du lundi au dimanche.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException(
                'Un relevé ne peut pas être supprimé ; son historique doit être conservé.',
            );
        });
    }

    /**
     * Semaine civile contenant la date fournie.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public static function weekFor(CarbonInterface|string $date): array
    {
        $day = is_string($date)
            ? DatePeriod::parse($date)->setTimezone(BusinessTime::TIMEZONE)
            : BusinessTime::toLocal($date)->startOfDay();

        $start = $day->startOfWeek(WeekDay::Monday->value);

        return [
            'start' => $start,
            'end' => $start->addDays(6),
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<TimesheetSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TimesheetSegment::class)->orderBy('start_at');
    }
}
