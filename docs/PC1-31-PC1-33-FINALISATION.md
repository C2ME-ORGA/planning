# Finalisation du Lot 5 — PC1-31 / PC1-33

**Branche :** `feature/pc1-31-pc1-33-finalisation-lot-5`  
**Base :** `develop` au commit `d04d3cf3f1e3d3f59381c0e09927b17eef4cee52`  
**Date de l'audit :** 1er novembre 2026

## Limites de l'audit

- Les tickets Jira PC1-31, PC1-32, PC1-33 et PC1-13 ne sont pas accessibles
  depuis l'environnement de développement (aucun connecteur, aucun export dans
  le dépôt). L'audit s'appuie sur le code, les tests, `docs/SPECIFICATIONS.md`,
  `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TESTING.md` et
  `docs/STATUS_RECONCILIATION.md`.
- Le dépôt contient une contradiction de numérotation : `docs/SECURITY.md` et
  le commit `97339fc` rattachent PC1-33 à l'authentification terrain, tandis que
  `docs/STATUS_RECONCILIATION.md` et la présente mission le rattachent aux
  corrections/réouvertures. Cette contradiction doit être levée dans Jira avant
  de clôturer le ticket.
- La présence d'un modèle, d'un écran ou d'un service ne vaut pas acceptation :
  les critères ci-dessous sont vérifiés par le code et les tests, mais la
  recette métier reste humaine.

## Décision de périmètre

La matrice des états intègre désormais le passage direct
`Brouillon → Prête pour validation`, réservé au Bureau/Direction et motivé.
Après correction d'un brouillon, le Bureau/Direction peut le transmettre
directement à la validation finale sans le rendre au salarié pour une nouvelle
transmission. L'événement `timesheet.sent_to_validation` est dédié, attribué et
intégré à la complétude du cycle courant.

L'indépendance du validateur reste garantie : l'auteur de la transmission
directe est traité comme contrôleur et ne peut pas valider un relevé dont il
n'est pas propriétaire. Le propriétaire Bureau/Direction conserve
l'auto-validation motivée définie en PC1-32. Les chefs d'équipe et les
conducteurs de travaux ne disposent ni de la transmission directe ni de
l'auto-validation.

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
| Transmission directe d'un brouillon corrigé par le Bureau | Conforme | `TimesheetControlService::sendToValidation`, transition dédiée |
| Indépendance du validateur après transmission directe | Conforme | `TimesheetActorHistory` traite l'auteur comme contrôleur |
| Chefs et conducteurs exclus de la transmission directe | Conforme | policy `sendToValidation`, tests de refus |
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
3. **Transmission directe Bureau/Direction** — nouvelle transition
   `Brouillon → Prête pour validation`, réservée au rôle Bureau/Direction,
   motivée, revalidant les segments sous verrou et auditée par
   `timesheet.sent_to_validation`. L'action Livewire est exposée dans la fiche
   détail ; l'auteur est exclu de la validation finale d'un relevé dont il n'est
   pas propriétaire.
4. **Tests de non-régression** — modification directe hors brouillon,
   affichage de l'avertissement de durée, conservation et ordre de l'historique
   après deux cycles, rollback d'une revue de chevauchement en cas d'échec
   d'audit, transmission directe autorisée/interdite, indépendance du validateur
   et auto-validation motivée après transmission directe.

## Résultats des contrôles

| Contrôle | Résultat |
|---|---|
| Tests ciblés Lot 5 | 304 tests, 1 180 assertions, 0 échec |
| Suite complète | 589 tests, 588 réussis, 1 ignoré, 3 142 assertions |
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

Statut proposé : **prêt pour recette / validation humaine**, sous réserve de
confirmer la numérotation Jira. Le parcours de correction directe du
Bureau/Direction est implémenté et testé.

Critères de clôture proposés :

- retour en correction motivé et normalisé ;
- reprise par le salarié et nouvelle transmission ;
- correction historisée avec acteur, rôle et valeurs avant/après ;
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

Réserve d'ergonomie : la fiche détail expose l'action de transmission directe
mais pas un formulaire de saisie des segments pour le brouillon d'un autre
salarié. La correction de ce brouillon est couverte côté service et policy
(`TimesheetSegmentServiceTest`). À confirmer si Jira exige un écran Bureau
dédié avant clôture.

## Risques restants

- Les écritures de masse Eloquent ou Query Builder ne déclenchent pas les
  gardes du modèle ; elles restent réservées aux imports/maintenance.
- Les transitions de statut ne sont pas protégées par un trigger SQL ; les
  services et la policy restent l'autorité serveur.
- La visibilité détaillée des chevauchements pour les chefs d'équipe reste un
  arbitrage métier ouvert.
- La contradiction de numérotation PC1-33 doit être corrigée dans Jira avant
  clôture.
- L'écran de saisie des segments d'un brouillon appartenant à un autre salarié
  n'est pas ajouté ; la correction est possible via le service/policy et la
  transmission directe via la fiche détail.
