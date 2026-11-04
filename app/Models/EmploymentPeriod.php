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
    /** @use HasFactory<EmploymentPeriodFactory> */
    use HasFactory;

    /**
     * L'historique est strictement append-only : toute mise à jour Eloquent
     * directe et toute suppression sont refusées, sans exception ni autorisation
     * temporaire. La clôture légitime (sortie contrôlée) est écrite par
     * EmployeeLifecycleService via une écriture ciblée, sous verrou, sous
     * transaction et auditée.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException(
                'Une période d’emploi est historique : utilisez la procédure métier de sortie.',
            );
        });

        static::deleting(function (): void {
            throw new LogicException('Une période d’emploi ne peut pas être supprimée.');
        });
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
