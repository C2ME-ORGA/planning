<?php

namespace App\Models;

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;
use Database\Factories\CounterMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Mouvement du compteur individuel d'un salarié.
 *
 * Le journal est append-only : tout mouvement est immuable. Une correction
 * ne modifie ni ne supprime un mouvement existant ; elle crée un nouveau
 * mouvement correctif justifié.
 *
 * @property int $id
 * @property int $employee_id
 * @property CounterMovementType $type
 * @property CounterAdjustmentDirection|null $direction
 * @property int $volume_minutes
 * @property int $balance_before_minutes
 * @property int $balance_after_minutes
 * @property string $month
 * @property string|null $quarter
 * @property Carbon|null $payment_date
 * @property string|null $reason
 * @property string|null $comment
 * @property int $actor_id
 * @property string $source_reference
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'employee_id',
    'type',
    'direction',
    'volume_minutes',
    'balance_before_minutes',
    'balance_after_minutes',
    'month',
    'quarter',
    'payment_date',
    'reason',
    'comment',
    'actor_id',
    'source_reference',
    'occurred_at',
])]
class CounterMovement extends Model
{
    /** @use HasFactory<CounterMovementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CounterMovementType::class,
            'direction' => CounterAdjustmentDirection::class,
            'volume_minutes' => 'integer',
            'balance_before_minutes' => 'integer',
            'balance_after_minutes' => 'integer',
            'payment_date' => 'date',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Le journal des mouvements du compteur est append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Un mouvement du compteur ne peut pas être supprimé.');
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
