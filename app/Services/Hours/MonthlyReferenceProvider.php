<?php

namespace App\Services\Hours;

use App\Models\Employee;

/**
 * Source des références mensuelles individuelles.
 *
 * Le contrat reste indépendant du stockage : une implémentation pourra lire
 * une table historique ou un cache. Chaque changement porte une date d'effet
 * au premier jour d'un mois civil.
 *
 * Les changements doivent être fournis dans l'ordre chronologique de création.
 * Pour un même mois d'effet, le dernier changement retourné remplace le
 * précédent ; le fournisseur devra donc garantir un ordre déterministe.
 */
interface MonthlyReferenceProvider
{
    /**
     * @return iterable<MonthlyReferenceChange>
     */
    public function changesFor(Employee $employee): iterable;
}
