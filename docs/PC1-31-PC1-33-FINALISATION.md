# Finalisation du Lot 5 — PC1-31 / PC1-33

**Branche :** `feature/pc1-31-pc1-33-finalisation-lot-5`
**Base :** `develop` au commit `d04d3cf3f1e3d3f59381c0e09927b17eef4cee52`
**Date de l'audit :** 1er novembre 2026

## Référentiel Jira confirmé

- **PC1-31** : Saisie des relevés et segments horaires.
- **PC1-32** : Workflow de soumission, contrôle et validation.
- **PC1-33** : Correction et réouverture motivées des relevés.
- **PC1-13** : Définir les règles de validation et correction des feuilles
  d'heures.

PC1-33 concerne bien les corrections et réouvertures, pas l'authentification
terrain. La référence erronée de `docs/SECURITY.md` a été corrigée.

La présence d'un modèle, d'un écran ou d'un service ne vaut pas acceptation :
les critères ci-dessous sont vérifiés par le code et les tests, mais la recette
métier reste humaine.

## Décision de périmètre

La matrice des états intègre le passage direct
`Brouillon → Prête pour validation`, réservé au Bureau/Direction et motivé.
Après une **correction effective** d'un brouillon, le Bureau/Direction peut le
transmettre directement à la validation finale sans le rendre au salarié pour
une nouvelle transmission. L'événement `timesheet.sent_to_validation` est
dédié, attribué et intégré à la complétude du cycle courant.

La transmission directe exige une création, une modification réelle (valeurs
avant/après différentes) ou une suppression de segment réalisée par le même
acteur dans le cycle courant. Une écriture sans changement effectif, une
correction d'un autre utilisateur, d'un autre relevé ou d'un ancien cycle ne
constitue pas une preuve. La fiche détail expose au Bureau/Direction les
commandes d'ajout, de modification et de suppression des segments d'un
brouillon appartenant à un autre salarié ; les écritures passent par
`TimesheetSegmentService` et restent auditées.

L'indépendance du validateur reste garantie : l'auteur de la transmission
directe est traité comme contrôleur et ne peut pas valider un relevé dont il
n'est pas propriétaire. Le propriétaire Bureau/Direction conserve
l'auto-validation motivée définie en PC1-32. Les chefs d'équipe et les
conducteurs de travaux ne disposent ni de l'interface de correction Bureau, ni
de la transmission directe, ni de l'auto-validation.

Le parcours normal du salarié (`Brouillon → À contrôler → Prête → Validée`)
reste inchangé. Le motif obligatoire d'une journée attendue sans heure reste
différé au Lot 7 et n'est pas bloquant pour PC1-31.

## Matrice exigences / implémentation / preuves

| Exigence | État | Preuve principale |
|---|---|---|
| Relevé lié à un salarié et à une semaine lundi-dimanche | Conforme | `Timesheet::booted()`, contraintes SQL, `TimesheetModelTest` |
| Unicité salarié/semaine | Conforme | index unique, `TimesheetCreationServiceTest` |
| Saisie à la minute, sans secondes ni fractions | Conforme | `TimesheetMinutePrecision`, `UtcDateTime`, tests unitaires |
| Début strictement antérieur à la fin | Conforme | modèle, service, contrainte SQL |
| Segment traversant minuit dans la semaine | Conforme | `TimesheetWeekBounds`, tests segment/soumission |
| Bornes lundi 00:00 → dimanche 23:59 | Conforme | `TimesheetWeekBoundsTest` |
| Fuseau `Europe/Paris`, heures ambiguës/inexistantes | Conforme | `LocalDateTimeResolverTest`, `WorkedTimeCalculatorTest` |
| Relevé vide non transmissible | Conforme | `TimesheetSubmissionServiceTest` |
| Segment incomplet non transmissible | Conforme | revalidation à la soumission |
| Édition/suppression interdites hors brouillon | Conforme | services + gardes `updating`/`deleting` du modèle |
| Avertissement > 12 h non bloquant | Conforme | calcul 720/721 min + affichage semaine/détail |
| Chevauchements détectés, transmission non bloquée | Conforme | `TimesheetOverlapDetector`, audit de soumission |
| Revue obligatoire avant validation | Conforme | `TimesheetOverlapReviewService`, tests |
| Revue des chevauchements préservée sur le parcours direct | Conforme | revue obligatoire après transmission directe |
| Motifs obligatoires normalisés (10–500) | Conforme | `TimesheetReason`, tests |
| Séparation des tâches | Conforme | `TimesheetPolicy`, `TimesheetActorHistory` |
| Preuve d'une correction effective du Bureau dans le cycle courant | Conforme | `TimesheetActorHistory::hasCorrectedInCurrentCycle`, tests add/update/delete/no-op/cycle |
| Transmission directe d'un brouillon corrigé par le Bureau | Conforme | `TimesheetControlService::sendToValidation`, transition dédiée |
| Interface Bureau d'ajout/modification/suppression des segments | Conforme | `TimesheetDetail` + `TimesheetSegmentService`, tests Livewire |
| Refus d'une correction d'un autre acteur, d'un autre relevé ou d'un ancien cycle | Conforme | preuve bornée par acteur, sujet et frontière de cycle |
| Indépendance du validateur après transmission directe | Conforme | `TimesheetActorHistory` traite l'auteur comme contrôleur |
| Chefs et conducteurs exclus de la correction et de la transmission directe | Conforme | policy `update`/`sendToValidation`, tests de refus |
| Auto-validation Bureau/Direction motivée | Conforme | policy + service + événement dédié |
| Réouverture motivée d'un relevé validé | Conforme | `TimesheetValidationService::reopen`, tests |
| Nouveau cycle et conservation de l'historique | Conforme | frontière de cycle + test multi-cycles |
| Revue de chevauchement invalidée par un nouveau cycle | Conforme | `cycle_sequence` + empreinte |
| Audit attribué, rôle historique, before/after | Conforme | `AuditLogger`, `TimesheetHistory` |
| Atomicité métier/audit | Conforme | transactions + tests de rollback |
| Journal global réservé au Bureau/Direction | Conforme | policy `viewJournal`, `Journal`, tests |

## Corrections apportées dans cette branche

1. **Garde de modification des segments** — `TimesheetSegment::updating`
   refuse toute modification d'un segment appartenant à un relevé non
   brouillon, en complément du garde de suppression existant. Les services
   restent l'autorité principale ; le garde couvre les écritures directes via
   le modèle.
2. **Avertissement de durée visible** — `TimesheetSummary::durationWarnings()`
   projette les avertissements de `WorkedTimeCalculator` et un composant Blade
   les affiche dans la semaine et dans la fiche détail. L'avertissement est non
   bloquant et ne modifie aucun segment.
3. **Preuve de correction effective** — `TimesheetActorHistory` expose
   `hasCorrectedInCurrentCycle()`. Elle ne retient que les événements
   `timesheet.updated` du même acteur, sur le même relevé, après la dernière
   frontière de cycle. Une création ou une suppression de segment compte ; une
   modification ne compte que si `changed_fields` contient une valeur
   avant/après réellement différente. Le service `sendToValidation()` vérifie
   cette preuve sous verrou avant toute écriture.
4. **Transmission directe Bureau/Direction** — nouvelle transition
   `Brouillon → Prête pour validation`, réservée au rôle Bureau/Direction,
   motivée, revalidant les segments sous verrou et auditée par
   `timesheet.sent_to_validation`. L'action Livewire est exposée dans la fiche
   détail après correction effective ; l'auteur est exclu de la validation
   finale d'un relevé dont il n'est pas propriétaire.
5. **Interface de correction Bureau** — la fiche détail permet au
   Bureau/Direction d'ajouter, modifier et supprimer les segments d'un brouillon
   d'un autre salarié. Les commandes sont masquées aux autres rôles et chaque
   appel Livewire est réautorisé côté serveur. Les écritures restent confiées à
   `TimesheetSegmentService`, avec audit avant/après et `cycle_sequence` sur
   chaque événement de segment.
6. **Tests de non-régression** — modification directe hors brouillon,
   affichage de l'avertissement de durée, conservation et ordre de l'historique
   après deux cycles, rollback d'une revue de chevauchement en cas d'échec
   d'audit, preuve de correction (add/update/delete/no-op/autre acteur/ancien
   cycle), interface Bureau autorisée/interdite, transmission directe
   autorisée/interdite, indépendance du validateur et auto-validation motivée
   après transmission directe.

## Résultats des contrôles

| Contrôle | Résultat |
|---|---|
| Tests ciblés Lot 5 | 322 tests, 1 247 assertions, 0 échec |
| Suite complète | 607 tests, 606 réussis, 1 ignoré, 3 209 assertions |
| Laravel Pint `--test` | Vert |
| PHPStan / Larastan | 0 erreur |
| `git diff --check` | Propre |

Les résultats ci-dessus ont été obtenus localement sur MySQL
`planning_c2me_test`. Ils ne remplacent pas la CI ni la recette humaine.

## Proposition de mise à jour Jira

### PC1-31 — Saisie des relevés et segments

Statut proposé : **prêt pour recette / validation humaine**, pas clôture
automatique.

Critères de clôture proposés :

- saisie à la minute, refus des secondes/fractions et des intervalles inversés ;
- bornes de semaine et traversée de minuit vérifiées ;
- changements d'heure été/hiver couverts par des tests ;
- relevé vide et segment incomplet refusés à la transmission ;
- édition/suppression verrouillées hors brouillon ;
- avertissement > 12 h calculé et affiché sans blocage ;
- chevauchements détectés et transmis pour revue ultérieure ;
- autorisations serveur et comptes inactifs vérifiés.

Preuves : `TimesheetWeekBoundsTest`, `TimesheetModelTest`,
`TimesheetSegmentServiceTest`, `TimesheetSubmissionServiceTest`,
`WorkspaceEmployeeTest`, `WorkedTimeCalculatorTest`,
`LocalDateTimeResolverTest`.

Réserve : le motif de journée sans saisie reste au Lot 7.

### PC1-33 — Corrections et réouverture

Statut proposé : **prêt pour recette / validation humaine**. Le parcours de
correction directe du Bureau/Direction est implémenté et testé.

Critères de clôture proposés :

- retour en correction motivé et normalisé ;
- reprise par le salarié et nouvelle transmission ;
- correction historisée avec acteur, rôle et valeurs avant/après ;
- preuve d'une correction effective du même acteur dans le cycle courant
  (création, modification réelle ou suppression de segment) ;
- interface Bureau d'ajout, de modification et de suppression des segments d'un
  brouillon appartenant à un autre salarié ;
- transmission directe d'un brouillon corrigé par le Bureau/Direction, avec
  motif obligatoire et événement `timesheet.sent_to_validation` dédié ;
- indépendance du validateur : l'auteur de la transmission directe ne peut pas
  valider un relevé dont il n'est pas propriétaire ;
- préparation à la validation puis validation finale ;
- auto-validation Bureau/Direction motivée conservée, avec événement dédié ;
- réouverture motivée réservée à un acteur habilité ;
- nouveau cycle complet et historique des cycles précédents conservé ;
- revues de chevauchement obsolètes invalidées ;
- absence de doublon d'événement sur requête répétée ;
- transitions interdites refusées côté serveur.

Preuves : `TimesheetControlServiceTest`,
`TimesheetValidationServiceTest`, `TimesheetActorHistoryTest`,
`TimesheetOverlapReviewTest`, `TimesheetPolicyTest`, `WorkspaceBureauTest`,
`WorkspaceTeamTest`, `WorkspaceEmployeeTest`.

Réserve : la recette humaine reste nécessaire. La preuve de correction repose
sur le journal d'audit append-only ; une correction réalisée hors service
(Query Builder ou import) ne produit pas d'événement et ne peut donc pas servir
de preuve.

## Risques restants

- Les écritures de masse Eloquent ou Query Builder ne déclenchent pas les
  gardes du modèle et ne produisent pas de preuve de correction ; elles restent
  réservées aux imports/maintenance.
- Les transitions de statut ne sont pas protégées par un trigger SQL ; les
  services et la policy restent l'autorité serveur.
- La visibilité détaillée des chevauchements pour les chefs d'équipe reste un
  arbitrage métier ouvert.
