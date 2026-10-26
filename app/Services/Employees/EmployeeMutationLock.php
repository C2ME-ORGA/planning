<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Verrous MySQL nommés, ciblés sur des salariés.
 *
 * Ils sérialisent les écritures qui doivent revalider des invariants portant
 * sur plusieurs lignes (périodes, affectations, responsabilités, sortie), là où
 * une simple contrainte SQL ne peut pas exprimer l'absence de chevauchement de
 * plages. Les verrous sont toujours libérés, y compris en cas d'exception.
 */
class EmployeeMutationLock
{
    public function run(Employee|int $employee, Closure $callback): mixed
    {
        return $this->runMany([$employee], $callback);
    }

    /**
     * Acquiert les verrous de plusieurs salariés dans un ordre déterministe
     * (identifiants croissants) afin d'éviter les interblocages.
     *
     * @param  array<int, Employee|int>  $employees
     */
    public function runMany(array $employees, Closure $callback): mixed
    {
        $employeeIds = array_values(array_unique(array_map(
            static fn (Employee|int $employee): int => $employee instanceof Employee
                ? (int) $employee->getKey()
                : $employee,
            $employees,
        )));
        sort($employeeIds, SORT_NUMERIC);

        $timeout = max(0, (int) config('planning.employees.lock_timeout_seconds', 5));
        $acquired = [];

        try {
            foreach ($employeeIds as $employeeId) {
                $name = $this->lockName($employeeId);
                $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

                if ($result === null || (int) $result->acquired !== 1) {
                    throw new RuntimeException(
                        'Une autre opération est en cours sur ce salarié. Réessayez dans quelques instants.',
                    );
                }

                $acquired[] = $name;
            }

            return $callback();
        } finally {
            foreach (array_reverse($acquired) as $name) {
                try {
                    DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
                } catch (Throwable) {
                    // La connexion libère automatiquement ses verrous nommés si
                    // elle est perdue ; ne pas masquer l'exception d'origine.
                }
            }
        }
    }

    private function lockName(int $employeeId): string
    {
        return 'planning.employee.'.$employeeId;
    }
}
