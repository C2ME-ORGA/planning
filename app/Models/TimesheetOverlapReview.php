<?php

namespace App\Models;

use App\Enums\OverlapReviewDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * État courant de la revue d’un chevauchement pour un relevé donné.
 *
 * Une seule ligne existe par couple canonique de segments et par relevé : une
 * nouvelle décision remplace l’état courant. L’historique immuable des
 * décisions est conservé par `AuditLogger` (`timesheet.overlap_review_*`).
 *
 * @property int $id
 * @property int $timesheet_id
 * @property int $first_segment_id
 * @property int $second_segment_id
 * @property int $cycle_sequence
 * @property string $pair_key
 * @property string $fingerprint
 * @property OverlapReviewDecision $decision
 * @property string $reason
 * @property int $reviewed_by_id
 * @property Carbon $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'timesheet_id',
    'first_segment_id',
    'second_segment_id',
    'cycle_sequence',
    'pair_key',
    'fingerprint',
    'decision',
    'reason',
    'reviewed_by_id',
    'reviewed_at',
])]
class TimesheetOverlapReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_sequence' => 'integer',
            'decision' => OverlapReviewDecision::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Timesheet, $this>
     */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    /**
     * @return BelongsTo<TimesheetSegment, $this>
     */
    public function firstSegment(): BelongsTo
    {
        return $this->belongsTo(TimesheetSegment::class, 'first_segment_id');
    }

    /**
     * @return BelongsTo<TimesheetSegment, $this>
     */
    public function secondSegment(): BelongsTo
    {
        return $this->belongsTo(TimesheetSegment::class, 'second_segment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
