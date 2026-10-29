<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\TimeCategory;
use App\Support\BusinessTime;
use App\Support\TimesheetMinutePrecision;
use Carbon\CarbonImmutable;
use Database\Factories\TimesheetSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Segment horaire d'un relevé.
 *
 * `start_at` et `end_at` sont stockés en UTC ; les offsets locaux français
 * sont dérivés de ces instants à l'écriture, jamais fournis par l'appelant.
 *
 * @property int $id
 * @property int $timesheet_id
 * @property TimeCategory $category
 * @property CarbonImmutable|null $start_at
 * @property CarbonImmutable|null $end_at
 * @property int $start_offset_minutes
 * @property int $end_offset_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'timesheet_id',
    'category',
    'start_at',
    'end_at',
])]
class TimesheetSegment extends Model
{
    /** @use HasFactory<TimesheetSegmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TimeCategory::class,
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'start_offset_minutes' => 'integer',
            'end_offset_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TimesheetSegment $segment): void {
            $start = $segment->start_at;
            $end = $segment->end_at;

            if ($start === null || $end === null) {
                return;
            }

            if (! TimesheetMinutePrecision::isRespected($start, $end)) {
                throw new LogicException('Un segment est saisi à la minute, sans seconde ni fraction.');
            }

            if ($end->lessThanOrEqualTo($start)) {
                throw new LogicException('La fin d’un segment doit être postérieure à son début.');
            }

            // L'offset est une projection de l'instant : il ne peut pas diverger.
            $segment->start_offset_minutes = BusinessTime::offsetMinutesAt($start);
            $segment->end_offset_minutes = BusinessTime::offsetMinutesAt($end);
        });

        // Défense en profondeur : le service d'écriture reste la garantie
        // principale, mais une suppression directe via le modèle ne doit pas
        // contourner le verrouillage du relevé.
        static::deleting(function (TimesheetSegment $segment): void {
            $timesheet = $segment->timesheet()->first();

            if (! $timesheet instanceof Timesheet || ! $timesheet->status->isEditable()) {
                throw new LogicException(
                    'Un segment ne peut être supprimé que sur un relevé en brouillon.',
                );
            }
        });
    }

    /**
     * @return BelongsTo<Timesheet, $this>
     */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }
}
