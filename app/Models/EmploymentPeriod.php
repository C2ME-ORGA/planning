<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EmploymentPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Période d'emploi d'un salarié. Les dates sont inclusives. Une date de fin
 * nulle signifie que la période est ouverte.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['start_date', 'end_date'])]
class EmploymentPeriod extends Model
{
    private static bool $closureAuthorized = false;

    /** @use HasFactory<EmploymentPeriodFactory> */
    use HasFactory;

    /**
     * L'historique est append-only. La seule mutation autorisée est la clôture
     * d'une période encore ouverte (via la sortie contrôlée). Toute autre
     * modification et toute suppression sont refusées au niveau du modèle.
     */
    protected static function booted(): void
    {
        static::updating(function (EmploymentPeriod $period): void {
            if (! self::$closureAuthorized) {
                throw new LogicException(
                    'Une période d’emploi est historique : utilisez la procédure métier de clôture.',
                );
            }

            $dirty = array_values(array_diff(array_keys($period->getDirty()), ['updated_at']));

            if ($period->getOriginal('end_date') !== null
                || $dirty !== ['end_date']
                || $period->end_date === null
                || $period->end_date->lt($period->start_date)) {
                throw new LogicException(
                    'La clôture de la période d’emploi est invalide.',
                );
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Une période d’emploi ne peut pas être supprimée.');
        });
    }

    public static function authorizeClosure(\Closure $callback): mixed
    {
        self::$closureAuthorized = true;

        try {
            return $callback();
        } finally {
            self::$closureAuthorized = false;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
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
        return $this->end_date === null;
    }

    public function covers(CarbonInterface $start, ?CarbonInterface $end = null): bool
    {
        return $this->start_date->lte($start)
            && ($end === null
                ? $this->end_date === null
                : ($this->end_date === null || $this->end_date->gte($end)));
    }
}
