<?php

namespace App\Services\Hours;

use App\Models\Employee;

/**
 * Fournisseur sans référence individuelle.
 *
 * Il permet de livrer les calculs PC1-34 sans créer de stockage persistant :
 * la référence par défaut de la configuration s'applique à tous les salariés.
 * Une implémentation adossée à une table historisée pourra remplacer ce
 * fournisseur sans modifier les calculateurs.
 */
final class NullMonthlyReferenceProvider implements MonthlyReferenceProvider
{
    /**
     * @return iterable<MonthlyReferenceChange>
     */
    public function changesFor(Employee $employee): iterable
    {
        return [];
    }
}
