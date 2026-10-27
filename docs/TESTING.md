# Planning C2ME — Stratégie de tests

## Principes
Tester le comportement observable, les permissions et les invariants métier. Les tests des fonctionnalités critiques sont obligatoires même si le seuil global de couverture est atteint.

## Couverture
Objectif progressif : au moins 70 % puis 80 % de couverture globale. Le seuil ne remplace pas les tests spécifiques des calculs horaires, permissions, workflows, exports/versionnement et synchronisation hors ligne.

Au lot 0, un seuil de transition de 35 % est appliqué par `composer test:coverage` ; il doit être relevé progressivement avec l'ajout des fonctionnalités et de leurs tests.

## Base de données de test
Les tests automatisés utilisent une base MySQL dédiée et jetable, `planning_c2me_test`, configurée dans `phpunit.xml`. La CI démarre un service MySQL isolé. Ne jamais exécuter la suite de tests contre une base de développement ou de production.

## Tests à prévoir
- Permissions : matrice par rôle, accès direct et accès à des données hors périmètre.
- Comptes : activation unique/expirée, réinitialisation, 2FA, expiration et révocation.
- Salariés : périodes d’emploi, sortie/retour, historisation des équipes.
- Clients et chantiers : validation des champs, doublons non bloquants, archivage conditionnel, permissions de restauration, matrice des statuts et réouverture motivée.
- Planning : demi-journées, exclusions, chevauchements, archives, pas de préremplissage.
- Heures : précision minute, passage minuit, chevauchement, segment incomplet, seuil 12 heures, raison pour journée vide.
- Workflow : transitions admises/refusées, transmission idempotente, correction, validation, réouverture motivée, audit.
- Calculs : seuil hebdomadaire 39 h, frontières semaine/mois, travail effectif seulement, compteur mensuel, ajustements et journal.
- RH : absences, taux de panier conservé, grands déplacements manuels, avances et doublons.
- Clôture/export : gel, réouverture, accès, contenu, versionnement.
- Notifications : destinataires et absence de rappel injustifié.
- Signalements : accès, priorité, numérotation après confirmation, doublons et absence de secrets.
- Hors ligne : retry sans doublons, conservation en échec, conflit, isolement des comptes, logout, stockage saturé.
- Sauvegardes : test de restauration isolée.

## Automatisation
- Exécuter Pest, Pint en mode vérification, analyse statique et build front-end par CI.
- Les vérifications obligatoires doivent échouer la PR en cas d’échec.
- Documenter commandes et résultats réels ; ne jamais simuler un résultat.
- Tester les migrations sur base de test et valider la restauration en environnement isolé.

## Couverture du lot 2 (26/10/2026)

- `tests/Feature/Access/RolePermissionMatrixTest.php` : matrice des rôles,
  refus par défaut, absence d’auto-approbation, portée des exceptions.
- `tests/Feature/Access/PanelAccessTest.php` : panneau, 2FA, rôles autorisés,
  déconnexion d’un compte désactivé, lecture seule de l’audit.
- `tests/Feature/Access/AccountLifecycleTest.php` : amorçage CLI, activation à
  usage unique et expirée, changement de rôle, désactivation, révocation de
  session, protection du dernier Bureau.
- `tests/Feature/Audit/AuditLogTest.php` : chaîne, séquence, clé d’audit dédiée,
  rotation de clé, événements historiques `APP_KEY`, expurgation, détection
  d’une signature invalide et commande de vérification.

Les triggers MySQL d’audit ne sont pas supprimés dans les tests (le DDL MySQL
valide implicitement les transactions). La détection de falsification est donc
testée en insérant directement un événement dont la signature est invalide.

Résultats de référence du lot 2 : 45 tests, 304 assertions, Pint et Larastan
verts.

## Couverture du lot 3 (26/10/2026)

- `tests/Feature/Employees/EmployeeProfileTest.php` : salarié sans compte,
  liaison facultative et unique, préservation après désactivation du compte,
  refus par rôle et policies.
- `tests/Feature/Employees/EmploymentPeriodTest.php` : dates inversées,
  chevauchements partiels/complets/identiques, périodes adjacentes, périodes
  ouvertes, réembauche sans réactivation du compte, verrou ciblé et
  revalidation, refus des mises à jour et suppressions directes.
- `tests/Feature/Employees/TeamAssignmentTest.php` : équipe principale et
  affectation temporaire historisées, absence de modification de l'équipe
  principale, couverture par les périodes d'emploi, chevauchements, audit.
- `tests/Feature/Employees/TeamLeadershipTest.php` : compte actif et rôle
  compatible, couverture d'emploi, unicité du responsable par équipe, un seul
  salarié responsable à la fois toutes équipes confondues, périodes disjointes
  et adjacentes autorisées, réaffectation refusée si elle crée un chevauchement,
  réaffectation avec conservation de l'historique, blocage du retrait de rôle,
  de la désactivation et de la déliaison tant qu'une responsabilité subsiste,
  verrous salarié sur réaffectation, clôture et retrait de rôle.
- `tests/Feature/Employees/EmployeeExitTest.php` : blocage de sortie avec
  affectations/responsabilités actives, correction manuelle, annulation d'une
  affectation future, conservation de l'historique, réembauche, désactivation
  explicite du compte, absence de désactivation par défaut, absence de compte,
  rollback atomique en cas d'échec, refus d'une désactivation immédiate pour une
  sortie future.
- `tests/Feature/Employees/TeamProfileTest.php` : équipes, unicité du nom,
  refus par rôle et policies.
- `tests/Feature/Employees/EmployeePanelTest.php` : écrans Filament accessibles
  au Bureau, refusés à l'Administrateur et aux rôles hors panneau ; actions
  d'ajout de période, de sortie et de désactivation explicite du compte, y
  compris le maintien du blocage de sortie.
- `tests/Feature/Access/RolePermissionMatrixTest.php` : matrice étendue aux
  permissions `employees.*` et `teams.*`.
- `tests/Unit/DatePeriodTest.php` : analyse stricte des dates métier et
  normalisation des valeurs fournies par Filament.

Le test de concurrence du lot 3 ouvre une seconde connexion MySQL, y prend le
verrou nommé du salarié, vérifie que l'écriture concurrente est refusée, puis
libère le verrou et vérifie que l'écriture revalidée passe. Il démontre le
mécanisme de sérialisation ; ce n'est pas un test multi-processus.

Résultats de référence du lot 3 après corrections d’audit et revue technique :
118 tests, 635 assertions, Pint et Larastan verts. Les migrations ont été rejouées intégralement sur
`planning_c2me_test`, puis annulées et réappliquées pour vérifier les deux sens.

## Jeu de démonstration PC1-28 (27/10/2026)

- `tests/Feature/ClientsAndSites/DemoDatasetTest.php` : création des 16 clients
  et 40 chantiers, relations client-chantier, 6 chantiers archivés, 2 clients
  sans chantier, absence de compte et d'événement d'audit, idempotence sans
  doublon, préservation des données hors démonstration, refus sans `--confirm`,
  refus en production, refus d'une base ou d'un hôte non autorisés, maintien des
  permissions Filament existantes.
- Les données sont créées directement par `DemoClientsAndSitesSeeder`, sans
  passer par les services métier, afin de ne pas fabriquer de faux événements
  d'audit. La procédure de revue est décrite dans
  `docs/PC1-28-DEMO-REVIEW.md`.

Résultats de référence : 148 tests, 1240 assertions, Pint et Larastan verts.

## Couverture PC1-28 — décisions métier (27/10/2026)

- `tests/Feature/ClientsAndSites/ClientAndSiteServiceTest.php` : validation
  serveur des champs client et de l'e-mail, normalisation de l'e-mail, formats
  de téléphone français et internationaux, contact facultatif, détection de
  doublons non bloquante et exclusion du client courant, doublons par raison
  sociale et par e-mail testés séparément, absence de sauvegarde et d'audit
  lorsqu'une mise à jour ne change aucun attribut, statut initial imposé,
  acceptation de chaque transition autorisée et refus de chaque transition
  interdite, motif obligatoire pour annuler un chantier en cours, réouverture
  protégée par `sites.reopen`, motif et cible de réouverture, audit des
  transitions et réouvertures, archivage indépendant du statut, blocage des
  opérations sur chantier archivé, archivage client conditionnel y compris avec
  un chantier actif archivé, indépendance client/chantiers, restauration
  réservée à `clients.restore`, refus de modification d'un client archivé,
  refus de rattachement à un client archivé et refus de suppression physique
  par policy et par événement Eloquent.
- `tests/Feature/ClientsAndSites/ClientAndSitePanelTest.php` : pages de liste,
  création, consultation et édition, pays `France` par défaut, alerte de doublon
  non bloquante, visibilité et consultation d'un client archivé sans édition,
  actions de changement de statut et de réouverture dans la liste, refus
  d'archivage d'un client ayant un chantier actif, chantier archivé consultable
  mais non modifiable, absence d'action de suppression.
- `tests/Feature/ClientsAndSites/DemoDatasetTest.php` : champs structurés des
  clients de démonstration, e-mails obligatoires, statuts du référentiel,
  complétion des clients existants sans écraser les valeurs saisies.
- `tests/Feature/ClientsAndSites/ClientSchemaMigrationTest.php` : schéma client
  final sur une base MySQL neuve, unicité des colonnes, nullabilité de `address`,
  index `archived_at`, puis mise à niveau d'une base au schéma initial avec
  conservation de l'adresse, du téléphone, de l'e-mail et du statut historique.
  Ces tests utilisent une base MySQL temporaire créée et supprimée pour
  l'exécution.
- `tests/Feature/Access/RolePermissionMatrixTest.php` : `clients.restore` et
  `sites.reopen` accordées au seul rôle `bureau_direction`, avec un test
  explicite documentant l'absence de rôle `direction` séparé.

Les transitions et réouvertures sont testées au niveau service, pas seulement
via Filament. Les tests Filament vérifient la visibilité et le branchement des
actions ; les règles restent imposées par les services et les policies.

Résultats de référence PC1-28 : 216 tests, 1571 assertions, 1 test ignoré
(création de lien symbolique indisponible sous Windows), Pint et Larastan
verts. Les migrations ont été rejouées dans les deux sens sur
`planning_c2me_test` avec `php artisan migrate:refresh --force`, et les deux
scénarios neuf/mise à niveau sont couverts sur des bases MySQL temporaires.

## Couverture PC1-29 — planning à la demi-journée

- `tests/Feature/Planning/PlanningAssignmentServiceTest.php` : création d'une
  affectation chantier/équipe/date/demi-journée, refus des dates invalides, des
  chantiers archivés, des exclusions non membres et des doublons stricts,
  exclusions valides et dédupliquées, composition limitée aux périodes d'emploi,
  préservation de l'équipe principale et d'une affectation temporaire,
  chevauchement d'équipe et de salarié averti sans
  blocage ni modification de l'existant, résolution d'un conflit salarié par
  exclusion, séparation matin/après-midi et dates adjacentes, modification
  auditée, autorisations positives et négatives, rollback complet si l'audit
  échoue, refus de suppression physique, et contrôle de non-régression du nombre
  de requêtes lors de la détection de chevauchements multi-équipes.
- `tests/Feature/Planning/PlanningAssignmentPanelTest.php` : écrans Filament
  réservés au Bureau/Direction, refus à l'Administrateur, création et
  modification via Livewire, références inexistantes refusées, avertissement de
  chevauchement affiché après création et depuis l'action de vérification,
  exclusions rechargées dans le formulaire de modification.
- `tests/Support/PlanningFixtures.php` : fixtures de chantier, d'équipe et de
  salarié membre pour les tests de planning.

Commandes et résultats réels :

```powershell
php artisan test tests/Feature/Planning
```
**22 tests, 22 réussis, 116 assertions.**

```powershell
php artisan test
```
**241 tests, 240 réussis, 1 ignoré, 1710 assertions.** Le test ignoré est le
test de lien symbolique SQLite déjà présent, indisponible sous Windows.

Pint et Larastan sont verts. Aucun fichier frontend n'a été modifié : aucune
compilation d'assets n'était nécessaire.
