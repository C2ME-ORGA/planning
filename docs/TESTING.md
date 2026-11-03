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
- `tests/Feature/Access/SessionRevocationTest.php` : révocation individuelle et
  globale, génération globale, remember-me réellement rejoué, pilotes `file` et
  `database`, connexion dédiée, panne du nettoyage physique sans perte de
  sécurité, sessions Filament et Livewire, marqueurs absents/invalides,
  concurrence login/révocation, échecs logiques audités, rollback de migration
  refusé, préflight de sécurité, logout local sans rotation des autres
  appareils et recalcul des permissions.
- `tests/Feature/Audit/AuditLogTest.php` : chaîne, séquence, clé d’audit dédiée,
  rotation de clé, événements historiques `APP_KEY`, expurgation, détection
  d’une signature invalide et commande de vérification.

Les triggers MySQL d’audit ne sont pas supprimés dans les tests (le DDL MySQL
valide implicitement les transactions). La détection de falsification est donc
testée en insérant directement un événement dont la signature est invalide.

Résultats de référence du lot 2 : 45 tests, 304 assertions, Pint et Larastan
verts.

Résultats PC1-12 (02/11/2026) : 634 tests pertinents exécutés (633 réussis,
1 ignoré, 3384 assertions), Pint et PHPStan verts sur le périmètre modifié.

Les simulations de panne SQL utilisent `DB::beforeExecuting()`, qui échoue
avant l’exécution de la requête. Le test d’entrelacement de sortie salarié
conserve volontairement `DB::listen()` : il ne simule pas une panne, mais une
écriture concurrente après une relecture.

Le test ignoré est le contrôle de lien symbolique SQLite de
`DemoDatasetTest`, sans rapport avec PC1-12 : `symlink()` n’est pas disponible
sur cet environnement Windows sans privilège particulier. Il doit être exécuté
sur un runner Linux.

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

## Couverture du socle des relevés (Lot 5 — incrément 1, 28/10/2026)

- `tests/Unit/LocalDateTimeResolverTest.php` : résolution été/hiver, refus d'une
  heure inexistante, ambiguïté d'automne avec offset explicite, offset
  incompatible, formats invalides.
- `tests/Unit/WorkedTimeCalculatorTest.php` : passage de minuit sans double
  comptage, seuil strict de 12 heures, journées de 23 et 25 heures, segment
  traversant un changement d'heure et minuit, durée nulle/négative, secondes et
  fractions refusées.
- `tests/Feature/Timesheets/TimesheetSchemaTest.php` : colonnes, index, clés
  étrangères `restrict`, contraintes de statut, de semaine (lundi + 6 jours,
  fin avant début, huit jours, dimanche), de catégorie et d'ordre, unicité
  salarié/semaine, semaine à cheval sur deux années, réversibilité des deux
  migrations sur une base MySQL temporaire isolée.
- `tests/Feature/Timesheets/TimesheetModelTest.php` : semaine lundi–dimanche,
  changement d'année, année bissextile, normalisation d'un `CarbonInterface`
  dans un autre fuseau, refus des périodes non conformes, cast du statut,
  stockage UTC et restitution locale, offsets dérivés des instants (y compris
  pendant l'heure ambiguë d'automne), refus d'une chaîne de date-heure sans
  contexte, refus des secondes et des durées non positives, suppression
  physique refusée.
- `tests/Feature/Timesheets/TimesheetOverlapTest.php` : chevauchement entre
  deux relevés d'un même salarié, entre catégories d'un même relevé, exclusion
  automatique du segment courant, segments adjacents et salarié différent,
  vérification d'une plage candidate, intervalle inchangé, déplacement avec et
  sans conflit, projection d'avertissement sans donnée nominative.
- `tests/Unit/TimesheetEnumsTest.php` : cinq catégories autorisées, cinq états,
  libellés et options.

Commandes et résultats réels :

```powershell
php artisan test tests/Unit/LocalDateTimeResolverTest.php tests/Unit/WorkedTimeCalculatorTest.php tests/Unit/TimesheetEnumsTest.php tests/Feature/Timesheets tests/Feature/Planning/PlanningNoRealHoursTest.php
```
**40 tests, 40 réussis, 158 assertions.**

```powershell
php artisan test
```
**305 tests, 304 réussis, 1 ignoré, 2022 assertions.** Le test ignoré est le
test de lien symbolique déjà présent, indisponible sous Windows. Un
avertissement PHPUnit sans détail a été signalé ; il n'a pas fait échouer la
suite. `composer ci:check` couvre 75,6 % du code, au-dessus du seuil de 35 %.

Pint et Larastan (niveau 7) sont verts. Le test PC1-30
`PlanningNoRealHoursTest` a été ajusté et renforcé : il vérifie désormais
l'absence de colonnes `timesheet_id`/`timesheet_segment_id` et de clé étrangère
vers `timesheets` sur `planning_assignments`, et l'absence de lignes dans
`timesheets` et `timesheet_segments` après création, modification, consultation
et consultation mobile du planning.

**Corrections issues de la revue technique :** les offsets sont dérivés des
instants UTC (`App\Support\BusinessTime`), `UtcDateTime` refuse les chaînes
naïves, le détecteur expose `overlapsForSegment()`/`overlapsForCandidate()` et
les tests d'auto-chevauchement ont été corrigés. L'incrément 1 n'est pas déclaré
validé : la validation reste humaine.

## Couverture du 2B des relevés (Lot 5 — saisie et transmission, 29/10/2026)

- `tests/Feature/Timesheets/TimesheetReasonTest.php` : motif facultatif absent
  ou blanc, nettoyage `trim`, motif obligatoire manquant, 500 caractères
  acceptés et 501 refusés, texte multioctet, absence de valeur inventée.
- `tests/Feature/Timesheets/TimesheetReasonAuditTest.php` : la valeur normalisée
  est acceptée par la policy `reopen` puis enregistrée à l'identique dans
  `audit_events.reason`, avec un acteur identifié.
- `tests/Feature/Timesheets/TimesheetCreationServiceTest.php` : ouverture par le
  propriétaire, normalisation de la semaine au lundi, création par le Bureau
  pour un salarié sans compte lié, refus d'un autre salarié et de
  l'Administrateur, retour d'un relevé existant uniquement si l'acteur peut le
  consulter, absence de doublon, audit `timesheet.created` attribué, rollback si
  l'audit échoue.
- `tests/Feature/Timesheets/TimesheetSegmentServiceTest.php` : ajout par le
  propriétaire et par le Bureau, dérivation des offsets, passage de minuit dans
  la semaine, dernière minute du dimanche acceptée, début avant la semaine et
  franchissement de la limite refusés, secondes/fractions, plage inversée et
  catégorie inconnue refusées, acteur non autorisé, relevé non brouillon, objet
  périmé revalidé sous verrou, mise à jour auditée, segment d'un autre salarié
  refusé, suppression auditée, garde de suppression du modèle hors brouillon,
  rollback si l'audit échoue.
- `tests/Feature/Timesheets/TimesheetSubmissionServiceTest.php` : transmission
  valide et audit `timesheet.submitted` attribué, brouillon sans segment refusé,
  Bureau et chef refusés, salarié sans compte lié refusé, compte inactif refusé,
  idempotence par état sans second audit, retour d'un relevé déjà transmis
  même si ses segments sont incohérents (sans transition ni audit), rejeu par
  un autre compte refusé,
  aucune écriture de segment après transmission, dernier segment supprimé puis
  transmission refusée, revalidation de la précision à la minute (secondes non
  nulles refusées, segment à la minute entière accepté), revalidation des bornes
  de semaine (segment hors semaine refusé, franchissement dimanche/lundi refusé,
  segment valide traversant minuit accepté), avertissements de durée et de
  chevauchement non bloquants consignés dans le contexte, état incompatible
  refusé, rollback si l'audit échoue.
- `tests/Feature/Timesheets/TimesheetWeekBoundsTest.php` : bornes UTC des
  semaines contenant les changements d'heure de mars et d'octobre, acceptation
  d'un instant valide dans ces semaines, refus d'un instant postérieur à la
  semaine d'octobre.
- `tests/Feature/Timesheets/TimesheetActorHistoryTest.php` (étendu) : frontière
  de cycle par la dernière action `reopened` ou `correction_resumed`, événements
  de complétude exigés après la frontière, frontière sans acteur refusée,
  absence de frontière compatible, ancien validateur/réouvertureur non exclu du
  seul fait de ces actions, `correction_resumed` non traité comme préparation.

Commandes et résultats réels :

```powershell
php artisan test tests/Feature/Timesheets/TimesheetReasonTest.php tests/Feature/Timesheets/TimesheetReasonAuditTest.php tests/Feature/Timesheets/TimesheetCreationServiceTest.php tests/Feature/Timesheets/TimesheetSegmentServiceTest.php tests/Feature/Timesheets/TimesheetSubmissionServiceTest.php tests/Feature/Timesheets/TimesheetWeekBoundsTest.php
```
**52 tests, 52 réussis, 139 assertions.**

```powershell
php artisan test tests/Feature/Timesheets/TimesheetActorHistoryTest.php
```
**11 tests, 11 réussis, 21 assertions** (6 tests/11 assertions en 2A, 5 tests
ajoutés en 2B).

```powershell
php artisan test tests/Feature/Timesheets
```
**115 tests, 115 réussis, 346 assertions.**

```powershell
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```
**Pint : passé. Larastan niveau 7 : 0 erreur.**

**Limite de concurrence :** `RefreshDatabase` exécute chaque test dans une
transaction unique ; une seconde connexion ne voit donc pas les données non
commitées et un test multi-connexion de verrou de ligne ne peut pas être fiable.
Aucun test n'est présenté comme une preuve de concurrence multi-processus. Les
invariants couverts sont : revalidation du statut sous verrou avec un objet
périmé, sérialisation de la transmission et des écritures de segments par le
même relevé parent, refus d'écriture après transmission, refus de transmettre
après suppression du dernier segment, et rollback complet en cas d'échec
d'audit. Un test multi-processus reste à prévoir si l'infrastructure le permet.
Un test d'intégration dédié, hors `RefreshDatabase`, pourra utiliser deux connexions MySQL sur des données commitées pour vérifier le comportement du verrouillage du relevé parent : une opération concurrente doit attendre la libération du verrou ou échouer si le délai d'attente est dépassé (`innodb_lock_wait_timeout`). Ce test permettra de vérifier la sérialisation des accès via InnoDB, sans constituer pour autant une preuve de concurrence multi-processus sur l'ensemble du workflow.

## Couverture du 2C — contrôle et validation des relevés (Lot 5, 29/10/2026)

- `tests/Feature/Timesheets/TimesheetControlServiceTest.php` : retour en
  correction par le chef de périmètre et le Bureau, motif normalisé et audité,
  refus du propriétaire et d'un chef hors périmètre, motif absent/blanc/trop
  long, statut incompatible, répétition sans second événement, rollback si
  l'audit échoue ; un acteur non habilité avec un motif invalide reçoit un
  refus d'autorisation, pas une erreur de validation ; reprise de correction
  par le propriétaire et le Bureau, refus des autres acteurs, répétition et
  rollback ; préparation à la validation avec motif, refus sans motif ou hors
  périmètre, répétition et rollback.
- `tests/Feature/Timesheets/TimesheetValidationServiceTest.php` : validation
  finale par un Bureau avec piste complète et événement attribué, refus si la
  piste est incomplète, rôle insuffisant, conducteur, administrateur,
  propriétaire, préparateur, contrôleur ou statut incompatible, répétition sans
  second événement et rollback ; refus de valider lorsque les seuls événements
  de complétude sont antérieurs à la dernière frontière ; réouverture motivée
  avec motif normalisé, refus d'un motif invalide, d'un statut incompatible ou
  d'un acteur non habilité (même avec un motif invalide), répétition et
  rollback ; édition des segments à nouveau possible après réouverture ;
  parcours complet création → segment → transmission → contrôle → validation ;
  nouveau cycle complet après réouverture ; nouveau cycle après retour en
  correction et reprise, avec nouvelle transmission et nouveau contrôle ;
  Bureau ayant repris la correction autorisé à valider ensuite ; auto-validation
  Bureau avec motif de 10 et 500 caractères accepté, refus à 9 et 501
  caractères, motif normalisé et écrit sur les deux événements ; rollback des
  deux événements d'auto-validation si l'audit échoue ; refus des chefs,
  conducteurs, administrateurs et comptes inactifs.
- `tests/Feature/Timesheets/TimesheetPolicyTest.php` (étendu) : conducteur et
  administrateur interdits de validation, chef hors périmètre, motifs de
  contrôle de 9 caractères refusés et de 10 acceptés, compte inactif refusé sur
  les capacités métier, suppression physique toujours interdite.
- `tests/Feature/Timesheets/TimesheetReasonTest.php` (étendu) : motif
  obligatoire de 0, 9, 10, 500 et 501 caractères, espaces seuls refusés,
  normalisation `trim()` avant validation et enregistrement.
- `tests/Feature/Timesheets/TimesheetControlServiceTest.php` (étendu) : motifs
  de contrôle de 9 caractères refusés, compte inactif refusé pour la demande de
  correction, la préparation et la reprise, sans changement de statut ni audit.
- `tests/Feature/Timesheets/TimesheetSubmissionServiceTest.php` (étendu) :
  compte inactif refusé sans modification de statut ni événement de succès ;
  Bureau propriétaire de `TimesheetOwn` mais non propriétaire du relevé refusé
  pour la transmission, sans événement d'audit.
- `tests/Feature/Access/ApprovalRulesTest.php` : auto-validation Bureau
  enregistrée avec motif normalisé, refus d'un compte inactif, d'un
  propriétaire différent de l'acteur, d'un chef, d'un conducteur ou de
  l'administrateur, et d'un motif de 9 ou 501 caractères ; aucun événement
  d'auto-validation n'est écrit lors d'un refus.
- `tests/Feature/Timesheets/TimesheetInactivityTest.php` : un compte inactif ne
  peut ni ouvrir un relevé, ni ajouter, modifier ou supprimer un segment, sans
  création, modification ou événement d'audit indu.
- `tests/Feature/Timesheets/TimesheetActorHistoryTest.php` (étendu) : la
  création reste l'origine globale du relevé, sans nouvel événement fabriqué
  après une frontière ; un contrôle antérieur à la dernière transmission du
  cycle est refusé ; chaque cycle exige une nouvelle transmission attribuée
  puis un nouveau contrôle attribué, y compris sur plusieurs cycles successifs ;
  la dernière transmission est retenue lorsqu'il en existe plusieurs, et
  plusieurs contrôles sont acceptés si l'un suit cette transmission ; toute
  frontière de cycle sans acteur, même antérieure à la dernière, bloque la
  validation.

Commandes et résultats réels :

```powershell
php artisan test tests/Feature/Timesheets/TimesheetControlServiceTest.php tests/Feature/Timesheets/TimesheetValidationServiceTest.php
```
**35 tests, 35 réussis, 117 assertions.**

```powershell
php artisan test tests/Feature/Timesheets
```
**155 tests, 155 réussis, 476 assertions.**

```powershell
php artisan test
```
**444 tests, 443 réussis, 1 ignoré, 2480 assertions.** Le test ignoré est le
test de lien symbolique Windows déjà présent.

Pint et Larastan (niveau 7) sont verts. Les tests restent séquentiels : la
limite de concurrence multi-processus décrite pour le 2B s'applique également
aux transitions de contrôle et de validation.

## Couverture PC1-30 — historique, conflits et mobile

- `tests/Feature/Planning/PlanningHistoryTest.php` : projection autorisée de
  l'historique, valeurs avant/après, exclusions ajoutées/retirées, avertissements
  et absence d'exposition du contexte technique.
- `tests/Feature/Planning/PlanningDayBoardTest.php` : consultation d'une date,
  séparation matin/après-midi, conflits groupés et absence de régression du
  chargement en lot.
- `tests/Feature/Planning/PlanningArchivedSiteTest.php` : consultation des
  affectations d'un chantier archivé et refus de toute modification directe ou
  Filament.
- `tests/Feature/Planning/PlanningMobileTest.php` : accès autorisé/refusé,
  navigation par date, états vides, erreurs de date et affichage mobile des
  conflits et de l'historique.
- `tests/Feature/Planning/PlanningNoRealHoursTest.php` : aucune création ou
  préremplissage de relevé, pointage ou segment horaire par le planning.

Résultats PC1-30 observés localement : 48 tests ciblés réussis (290 assertions),
puis 267 tests de suite réussis à l'exception d'un test ignoré Windows déjà
existant (1884 assertions). Pint et PHPStan/Larastan sont verts. Aucun fichier
frontend compilé séparément n'est nécessaire : l'interface utilise Livewire et
les vues Blade existantes.

## Couverture PC1-32 — Lot A : chevauchements persistants

- `tests/Feature/Timesheets/TimesheetOverlapReviewTest.php` : paire canonique
  unique quel que soit l'ordre de détection, deux chevauchements indépendants,
  segments adjacents sans revue, permission dédiée refusée aux rôles non
  Bureau (y compris avec `timesheet.validate`), refus hors des états `à
  contrôler` et `prête pour validation`, décisions acceptée et rejetée avec
  motif normalisé et événement d'audit dédié, motifs vide/9/501 caractères
  refusés sans écriture, validation bloquée sans revue ou avec revue rejetée,
  validation autorisée après acceptation, empreinte invalidée par modification
  d'un segment, nouveau cycle de correction imposant une nouvelle revue,
  correction supprimant le chevauchement sans revue inutile, et conservation
  de l'historique d'audit (une ligne d'état courant, plusieurs événements).
- `tests/Feature/Timesheets/TimesheetSchemaTest.php` (étendu) : la migration
  `timesheet_overlap_reviews` est annulée puis rejouée avec les migrations des
  relevés.

Résultats observés localement : 15 tests ciblés réussis (64 assertions), puis
suite complète à 503 tests dont 502 réussis et 1 ignoré (2 720 assertions). Pint
et PHPStan sont verts.

## Couverture PC1-32 — Lot B : validation en masse

- `tests/Feature/Timesheets/TimesheetBatchValidationTest.php` : validation de
  plusieurs relevés avec un événement `timesheet.validated` par succès ;
  isolation d'un relevé déjà validé et d'un relevé préparé par l'acteur sans
  compromettre les succès situés avant et après ; garde-fou des chevauchements
  (non examiné, rejeté, accepté) appliqué par feuille ; maintien de la règle
  d'auto-validation motivée du Lot C ; déduplication des identifiants ; limite
  de 50 acceptée et 51 refusée avant traitement ; sélection vide ou malformée
  refusée ; contrôle du rôle Bureau/Direction et de `timesheet.validate` (y
  compris avec une dérogation de validation accordée à un non-Bureau et pour un
  compte inactif) ; isolation et journalisation d'une erreur technique
  inattendue avec poursuite du lot.
- `TimesheetPolicy::validateBatch()` : capacité globale réservée au
  Bureau/Direction disposant de `timesheet.validate`.

Résultats observés localement : 9 tests ciblés réussis (72 assertions), puis
suite complète à 512 tests dont 511 réussis et 1 ignoré (2 792 assertions). Pint
et PHPStan sont verts.

## Couverture PC1-32 — Lot D : interfaces métier

- `tests/Feature/Timesheets/WorkspaceEmployeeTest.php` : redirection vers
  l'espace salarié, tableau de bord et semaine, ouverture d'un brouillon via
  `TimesheetService`, ajout/modification/suppression de segments via
  `TimesheetSegmentService`, chevauchement affiché sans blocage, refus
  d'écriture sur un relevé non brouillon, transmission, affichage du motif de
  correction, reprise via `TimesheetControlService` et nouvelle transmission,
  refus de la fiche d'un autre salarié par route et par composant, blocage de
  la file des chevauchements pour un salarié et verrouillage de l'identifiant
  du composant de détail.
- `tests/Feature/Timesheets/WorkspaceTeamTest.php` : tableau de bord et liste
  limités au périmètre, absence du salarié hors équipe, refus de la fiche hors
  périmètre, retour pour correction et préparation à la validation motivés,
  refus de la validation finale et de la revue des chevauchements par appel
  direct, et équivalence entre la portée SQL de `TimesheetQuery` et
  `TimesheetPerimeter` sur des périodes historisées.
- `tests/Feature/Timesheets/WorkspaceBureauTest.php` : accès aux files et
  tableaux de bord, revue de chevauchement acceptée et rejetée avec motif
  obligatoire, revue obsolète après un nouveau cycle de correction et nouvelle
  décision, validation individuelle, auto-validation Bureau motivée avec ses
  deux événements d'audit, réouverture motivée, validation en masse avec
  résultat partiel et détail par relevé, refus d'une sélection vide ou de plus
  de 50 éléments, journal global filtré côté serveur.
- `tests/Feature/Timesheets/WorkspaceSecurityTest.php` : redirection des
  invités, refus des pages Bureau pour l'Administrateur, le conducteur, le chef
  d'équipe et l'ouvrier, refus du tableau de bord d'équipe sans
  `timesheet.control`, refus des composants de validation en masse et de
  journal pour un chef, et refus des files de contrôle après modification d'une
  propriété Livewire par un salarié.

Résultats observés localement : 41 tests ciblés réussis (208 assertions), puis
suite complète à 553 tests dont 552 réussis et 1 ignoré (3 000 assertions). Pint
et PHPStan sont verts.

## Couverture de finalisation Lot 5 — PC1-31 / PC1-33

- `tests/Feature/Timesheets/TimesheetSegmentServiceTest.php` (étendu) : une
  modification directe du modèle `TimesheetSegment` est refusée hors brouillon,
  même quand le service n'est pas utilisé ; le segment stocké reste inchangé.
  La suppression directe hors brouillon reste couverte par le test existant.
  Les événements `segment_added`, `segment_updated` et `segment_deleted`
  portent `cycle_sequence`.
- `tests/Feature/Timesheets/WorkspaceEmployeeTest.php` (étendu) : un segment de
  13 heures affiche l'avertissement de durée non bloquant dans la semaine et
  dans la fiche détail, sans changer le statut ni supprimer le segment.
- `tests/Feature/Timesheets/TimesheetValidationServiceTest.php` (étendu) : après
  une réouverture et un second cycle, l'historique contient toujours les deux
  transmissions et les deux validations, la création unique et l'événement de
  réouverture ; l'ordre des séquences est strictement croissant et la première
  validation précède la réouverture.
- `tests/Feature/Timesheets/TimesheetOverlapReviewTest.php` (étendu) : une
  erreur d'audit annule l'écriture de la revue de chevauchement et ne laisse ni
  état courant ni événement de décision.
- `tests/Unit/WorkedTimeCalculatorTest.php` (existant, vérifié) : 720 minutes
  ne produisent aucun avertissement, 721 minutes en produisent un.
- `tests/Unit/TimesheetStatusTest.php` (étendu) : sept transitions de référence,
  dont `Brouillon → Prête pour validation` avec motif obligatoire.
- `tests/Feature/Timesheets/TimesheetPolicyTest.php` (étendu) : transmission
  directe réservée au Bureau/Direction disposant de `timesheet.validate`, motif
  obligatoire, refus des salariés, chefs, conducteurs, comptes inactifs et
  statuts autres que brouillon.
- `tests/Feature/Timesheets/TimesheetControlServiceTest.php` (étendu) :
  transmission directe d'un brouillon réellement corrigé, motif normalisé, audit
  `timesheet.sent_to_validation` avec acteur et `path = bureau_correction`,
  refus sans correction effective, correction d'un autre acteur, modification
  sans changement, correction antérieure à la dernière frontière de cycle,
  correction réutilisée après réouverture, refus des rôles non habilités, motif
  invalide, relevé vide, segment hors semaine, requête répétée, compte inactif
  et rollback si l'audit échoue.
- `tests/Feature/Timesheets/TimesheetActorHistoryTest.php` (étendu) : la
  transmission directe est une action de contrôle, complète le cycle courant,
  doit être postérieure à la dernière frontière et doit être attribuée. La
  preuve `hasCorrectedInCurrentCycle()` reconnaît une création, une
  modification réelle ou une suppression, et refuse un no-op, un autre acteur,
  un autre relevé, une action non liée à un segment ou une correction d'un
  ancien cycle.
- `tests/Feature/Timesheets/TimesheetValidationServiceTest.php` (étendu) :
  validation par un second Bureau, refus du Bureau auteur de la transmission
  directe, auto-validation motivée du propriétaire Bureau, nouveau cycle après
  réouverture et parcours complet reprise de correction → correction → envoi
  direct → validation indépendante.
- `tests/Feature/Timesheets/TimesheetOverlapReviewTest.php` (étendu) : une
  transmission directe par le Bureau ne contourne pas la revue des
  chevauchements ; la validation reste bloquée jusqu'à une décision acceptée.
- `tests/Feature/Timesheets/WorkspaceBureauTest.php` (étendu) : action de
  transmission directe masquée avant correction effective puis affichée après
  modification d'un segment ; ajout et suppression de segments sur le brouillon
  d'un autre salarié avec audit `segment_added`/`segment_deleted` ; refus de
  correction sur un relevé verrouillé ; refus de transmission directe sans
  preuve ; transmission autorisée après correction.
- `tests/Feature/Timesheets/WorkspaceTeamTest.php` et
  `tests/Feature/Timesheets/WorkspaceEmployeeTest.php` (étendus) : interface de
  correction Bureau absente et refusée par appel direct pour un chef, un
  conducteur ou un salarié ; action de transmission directe absente et refusée.

Commande ciblée :

```
php artisan test tests/Feature/Timesheets tests/Unit/LocalDateTimeResolverTest.php tests/Unit/WorkedTimeCalculatorTest.php tests/Unit/TimesheetEnumsTest.php tests/Unit/TimesheetStatusTest.php
```

Résultats observés localement : 322 tests ciblés réussis (1 247 assertions),
puis suite complète à 607 tests dont 606 réussis et 1 ignoré (3 209
assertions). Pint `--test` est vert et PHPStan ne remonte aucune erreur.


## PC1-14 — Vérification périodique de la suppléance

Contrôle tous les trois mois et après toute évolution importante de l'authentification, des rôles ou des permissions. Responsable : personne physique habilitée utilisant le compte de référence `direction@c2me.ovh`, avec participation de Corentin Doizy.

| Contrôle | Résultat attendu | Preuve |
|---|---|---|
| Compte `c.doizy@c2me.ovh` | Actif et nominatif | Fiche datée |
| Authentification | Connexion et MFA opérationnelles | Résultat sans secret |
| Rôle | Bureau / Direction standard | Vérification des droits |
| Permissions | Conformes au rôle attendu | Liste/version de référence |
| Continuité | Accès aux fonctions nécessaires | Test non destructif |
| Audit | Actions attribuées au bon compte | Références d'événements |
| Titulaire principal | Accès indépendants maintenus | Résultat de vérification |
| Retour normal | Aucun changement indu | Clôture de la fiche |

Ne pas lancer `planning:emergency-lockdown` en production pour tester la suppléance : cette commande révoque toutes les sessions. Ne pas réaliser d'opération métier réelle ou destructive uniquement pour les tests.

### Modèle de fiche de contrôle

- Date :
- Contrôleur (nom et fonction) :
- Participant :
- Version / commit de l'application :
- Compte suppléant actif : Oui / Non
- Connexion et MFA : Réussi / Échec
- Rôle et permissions : Conforme / Non conforme
- Test de prise de relais non destructif : Réussi / Échec / Non réalisé
- Journal d'audit vérifié : Oui / Non
- Accès du titulaire principal vérifiés : Oui / Non
- Anomalies :
- Actions correctives, responsable et échéance :
- Conclusion :
- Date de clôture et visa du contrôleur :

Ne jamais inscrire de mot de passe, secret MFA, code de récupération ou lien d'activation.
