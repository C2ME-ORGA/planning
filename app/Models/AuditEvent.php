<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Événement du journal d'audit.
 *
 * Le journal est append-only : le modèle refuse toute mise à jour ou
 * suppression, et la migration ajoute des déclencheurs MySQL équivalents.
 * L'intégrité est vérifiée par la chaîne `previous_hash` / `hash`
 * (voir App\Services\AuditLogger::verify()).
 *
 * @property int $id
 * @property int $sequence
 * @property Carbon $occurred_at
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $reason
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $previous_hash
 * @property string $hash
 * @property string|null $key_id
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'action' => AuditAction::class,
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Le journal d’audit est append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Le journal d’audit est append-only.');
        });
    }
}
