<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exclusion individuelle d'un salarié pour une affectation de planning.
 *
 * Une exclusion retire le salarié des membres planifiés de cette affectation.
 * Elle ne crée aucune absence et ne produit aucun temps réel.
 *
 * @property int $id
 * @property int $planning_assignment_id
 * @property int $employee_id
 */
#[Fillable(['planning_assignment_id', 'employee_id'])]
class PlanningAssignmentExclusion extends Model
{
    /**
     * @return BelongsTo<PlanningAssignment, $this>
     */
    public function planningAssignment(): BelongsTo
    {
        return $this->belongsTo(PlanningAssignment::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
