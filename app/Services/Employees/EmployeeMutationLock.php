<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Verrou MySQL nommé, ciblé sur un salarié.
 *
 * Il sérialise les écritures qui doivent revalider des invariants portant sur
 * plusieurs lignes (périodes, affectations, sortie), là où une simple
 * contrainte SQL ne peut pas exprimer l'absence de chevauchement de plages.
 * Le verrou est libéré dans tous les cas, y compris en cas d'exception.
 */
class EmployeeMutationLock
{
    public function run(Employee|int $employee, Closure $callback): mixed
    {
        $employeeId = $employee instanceof Employee ? $employee->getKey() : $employee;
        $name = 'planning.employee.'.$employeeId;
        $timeout = max(0, (int) config('planning.employees.lock_timeout_seconds', 5));

        $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

        if ($result === null || (int) $result->acquired !== 1) {
            throw new RuntimeException(
                'Une autre opération est en cours sur ce salarié. Réessayez dans quelques instants.',
            );
        }

        try {
            return $callback();
        } finally {
            try {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            } catch (Throwable) {
                // La connexion libère automatiquement ses verrous nommés si
                // elle est perdue ; ne pas masquer l'exception d'origine.
            }
        }
    }
}
