# PC1-35 — Journal de mouvements du compteur

## État d'avancement

**En cours — Étape 1 terminée (migration et modèle).**

L'implémentation se déroule sur la branche :
`feature/PC1-35-journal-mouvements-compteur`

Commit de base : `3d477877937e19cfed9861fec46305b484f0a9bd` (`develop`)

Aucun commit n'a encore été effectué. Les fichiers sont créés et les tests ciblés passent.

## Objectif du ticket

Mettre en place un journal fiable, auditable et append-only des mouvements du compteur individuel des salariés (Lot 6 — Calculs et compteur, Jira PC1-10 / PC1-35).

Le compteur permet de tracer quatre types de mouvements : crédit, utilisation, paiement et ajustement. Il ne doit en aucun cas automatiser la paie, déclencher des virements ou générer des retenues salariales.

## Décisions métier validées

| Décision | Contenu |
|---|---|
| Crédit mensuel manuel | Jamais automatique. Le Bureau consulte les agrégats PC1-34 et déclenche explicitement le crédit. Idempotent : un même agrégat mensuel ne peut pas être crédité deux fois (référence source métier unique). |
| Références individuelles | Reportées (hors périmètre PC1-35). Utilisation du mécanisme PC1-34 : `MonthlyReferenceProvider`, `NullMonthlyReferenceProvider`, référence par défaut 10 140 min (169 h). Pas de table `employee_monthly_references`. |
| Ajustements réservés à la Direction | Volume strictement positif, direction explicite (`credit`/`debit`), motif obligatoire, confirmation explicite, traçabilité complète. Pas de volume signé. |
| Solde | Cumulatif, reporté d'un mois à l'autre. Formule : crédits − utilisations − paiements + ajustements crédit − ajustements débit. Aucun solde négatif, aucune dette automatique, aucune retenue automatique. |
| Audit | Mouvement + événement d'audit dans la même transaction. Modèle append-only (pas de modification/suppression). |

## Architecture cible (à adapter aux conventions du dépôt)

- `App\Models\CounterMovement` — modèle append-only.
- `App\Enums\CounterMovementType` — `credit`, `usage`, `payment`, `adjustment`.
- `App\Enums\CounterAdjustmentDirection` — `credit`, `debit` (uniquement pour `adjustment`).
- `App\Services\Counter\CounterMovementService` — création des quatre mouvements, verrou salarié, transaction, audit, idempotence.
- `App\Services\Counter\CounterBalanceService` — reconstitution du solde à partir du journal (lecture seule).
- `App\Policies\CounterMovementPolicy` — contrôle d'accès.
- `App\Enums\AuditAction` — `counter.credit`, `counter.usage`, `counter.payment`, `counter.adjustment`.
- `App\Enums\Permission` — `counter.view`, `counter.manage`.
- Migration : `create_counter_movements_table`.

## ÉTAPE 1 — Migration et modèle (TERMINÉE)

### Fichiers créés

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_11_04_000001_create_counter_movements_table.php` | Table `counter_movements` : colonnes, index, contraintes CHECK SQL |
| `app/Models/CounterMovement.php` | Modèle append-only (refuse `update`/`delete`), casts enum, relations `employee()`/`actor()` |
| `app/Enums/CounterMovementType.php` | Enum des types de mouvement |
| `app/Enums/CounterAdjustmentDirection.php` | Enum des directions d'ajustement |
| `database/factories/CounterMovementFactory.php` | Factory pour les tests |
| `tests/Unit/Counter/CounterMovementModelTest.php` | Tests append-only, casts, relations, nullabilité |
| `tests/Unit/Counter/CounterMovementTypeTest.php` | Tests libellés et options des enums |
| `tests/Feature/Counter/CounterMovementMigrationTest.php` | Tests colonnes et contraintes CHECK |

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `app/Enums/AuditAction.php` | Ajout `CounterCredit`, `CounterUsage`, `CounterPayment`, `CounterAdjustment` |
| `app/Enums/Permission.php` | Ajout `CounterView`, `CounterManage` |
| `tests/Pest.php` | `RefreshDatabase` appliqué globalement (pas seulement `Feature`) |

### Choix techniques retenus

- Table `counter_movements` sans colonne de solde courant sur `employees` (le solde est reconstitué à chaud).
- Contraintes CHECK SQL : type valide, volume > 0, soldes ≥ 0, direction cohérente avec le type, raison obligatoire pour ajustement.
- Colonne `source_reference` unique et nullable pour l'idempotence des crédits mensuels.
- Index : `(employee_id, occurred_at, id)` et `(employee_id, month)`.
- Modèle append-only selon le pattern `AuditEvent` / `EmploymentPeriod`.

### Résultats des tests ciblés (ÉTAPE 1)

| Outil | Résultat |
|---|---|
| `php artisan test tests/Unit/Counter tests/Feature/Counter` | **19 tests réussis, 42 assertions** |
| `./vendor/bin/pint --test` | **Passé** |
| `./vendor/bin/phpstan analyse` | **0 erreur** (level 7) |

## ÉTAPE 2 à venir (non commencée)

Implémentation de `CounterBalanceService` :
- Reconstitution du solde à partir du journal.
- Ordre déterministe : `occurred_at`, puis `id`.
- Calcul du solde à une date donnée.
- Lecture seule, aucune modification de données.

## Restes à faire (PC1-35)

- `CounterMovementService` (quatre opérations, verrou `EmployeeMutationLock`, transaction, audit, idempotence).
- `CounterBalanceService` et tests unitaires.
- `CounterMovementPolicy` et permissions.
- Intégration avec les agrégats PC1-34 (`MonthlyHoursAggregator`, `credit_candidate_minutes`).
- Tests de sécurité et de concurrence.
- Vérification finale : Pint, PHPStan, tests ciblés uniquement.

## Hors périmètre PC1-35

- Clôture trimestrielle, snapshots de solde, réouverture de périodes (PC1-36).
- Références individuelles persistantes.
- Traitement de paie, montants en euros, ordres de paiement, virements, retenues automatiques.
- Notifications automatiques de déficit.
- Interface graphique complète non nécessaire au périmètre technique.

## Règles Git et livraison

Aucun commit, push, PR, fusion de branche ou déploiement n'a été effectué à ce stade. Ces actions nécessitent une autorisation explicite distincte.

## Préuve d'exécution

Les 19 tests de l'étape 1 ont été exécutés avec succès le 04/11/2026 sur la branche `feature/PC1-35-journal-mouvements-compteur`. La suite complète du projet n'a pas été exécutée et n'est donc pas déclarée validée.
