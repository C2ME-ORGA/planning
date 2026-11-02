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

## Durcissement concurrence et atomicité (PC1-52)

- `tests/Feature/Audit/AuditLogTest.php` couvre la pagination par `sequence`
  avec des identifiants SQL non corrélés, les séquences manquantes, les ruptures
  de `previous_hash`, le journal vide et un volume de 1 200 événements parcouru
  par lots bornés.
- `tests/Feature/Access/AccountLifecycleTest.php` couvre le marqueur durable
  `system_locks` du bootstrap, l’unicité de ce verrou, le rollback du compte et
  du verrou en cas d’échec d’audit, la réactivation, le changement de rôle sans
  doublon d’audit, la révocation de sessions, la réinitialisation MFA et les
  rollbacks d’audit.
- `tests/Feature/Access/RolePermissionMatrixTest.php` couvre le refus d’une
  seconde exception active, la double révocation, et le rollback de création ou
  de révocation lorsque l’audit échoue.
- Le verrouillage est testé sur MySQL via `lockForUpdate()`, la clé primaire de
  `system_locks` et des transactions réelles. La suite ne crée pas de processus
  concurrents (pas de `pcntl` sous Windows) : la garantie multi-processus repose
  sur les contraintes et verrous SQL documentés, pas sur un test de fork.
- Les sessions `file`/`redis` ne peuvent pas être annulées par une transaction
  SQL ; les tests utilisent le pilote `array` et vérifient la table `sessions`
  ainsi que les remember tokens.

Résultats de référence du lot 2 après durcissement : 76 tests, 397 assertions,
Pint et Larastan verts.
