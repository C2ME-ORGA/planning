# Planning C2ME --- Architecture technique

**Statut :** proposition d'architecture initiale à valider avant
implémentation.\
**Référence fonctionnelle :** `docs/SPECIFICATIONS.md`\
**Instructions agent :** `AGENTS.md`

## 1. Objectifs d'architecture

L'architecture doit fournir une application indépendante pour la gestion
du planning et des relevés d'heures de SARL C2ME, avec interface
mobile/PWA, contrôle strict des autorisations, calculs testables et mode
hors ligne limité.

Principes : - monolithe modulaire Laravel pour le MVP ; - règles métier
centralisées et testables ; - séparation entre planification, heures
réelles, compteurs, RH, exports et signalements ; - cohérence
transactionnelle des opérations critiques ; - sécurité côté serveur ; -
synchronisation idempotente et résolution explicite des conflits ; - pas
d'intégration BATISTACK dans le MVP.

## 2. Socle technologique

Socle vérifié et retenu au lot 0 : - PHP 8.4 ; - Laravel 13 ; - Filament 5
pour l'administration ; - Livewire 4 pour les interfaces interactives ; -
MySQL 8 pour tous les environnements, tests automatisés inclus ; - PWA
mobile (à implémenter) ; - Pest, Laravel Pint, Larastan et GitHub Actions.

Le panneau Filament est restreint par le rôle du compte (Administrateur ou
Bureau/Direction) et protégé par une 2FA TOTP obligatoire. Les policies,
gates et le journal d'audit chaîné sont décrits à la section 3.1.

### 3.1 Modèle d'accès et audit (lot 2)

-   **Rôles** : `administrateur`, `bureau_direction`, `conducteur_travaux`,
    `chef_equipe`, `ouvrier` ; un seul rôle par compte. Les rôles sont des
    enums et la matrice de permissions est centralisée dans
    `app/Enums/Permission.php`.
-   **Autorisation** : gates et policies serveur (`app/Providers/AccessServiceProvider.php`,
    `app/Policies/`). L'interface Filament ne fait que refléter ces règles.
    L'Administrateur ne possède que `audit.view`.
-   **Panneau** : `User::canAccessPanel()` n'autorise que l'Administrateur et
    le Bureau/Direction ; la 2FA est exigée par le panneau. Le middleware
    `EnsureAccountIsActive` est prioritaire sur l'authentification Filament
    afin qu'un compte désactivé soit déconnecté (et non renvoyé en 403).
-   **Comptes** : création par Bureau/Direction (`AccountProvisioner`), lien
    d'activation à usage unique (`AccountActivation`, contrôleur dédié),
    désactivation avec révocation des sessions (`SessionRevoker`), réinitialisation
    de la 2FA en CLI. Aucune suppression physique.
-   **Amorçage** : `planning:bootstrap-bureau` crée le premier Bureau/Direction,
    refuse si un tel compte existe déjà, puis se ferme. Aucune élévation de
    privilège par la CLI : chaque commande sensible exige `--actor` avec la
    permission requise, un motif et `--confirm`.
-   **Exceptions** : `PermissionException` ciblée, motivée, révocable, ≤ 12 mois,
    limitée à trois permissions opérationnelles. Service
    `PermissionExceptionService`, gestion en CLI uniquement.
-   **Audit** : table `audit_events` append-only, séquence contiguë, chaîne
    HMAC-SHA256 signée par une clé dédiée (`PLANNING_AUDIT_KEY`) distincte
    d'`APP_KEY`, triggers MySQL anti-`UPDATE`/`DELETE`, expurgation des clés
    sensibles et commande `planning:audit:verify`. Chaque événement stocke
    l'identifiant de sa clé ; les événements historiques signés avec `APP_KEY`
    restent vérifiables et les anciennes clés d'audit peuvent être déclarées
    dans `PLANNING_AUDIT_PREVIOUS_KEYS`.
    Les lots ultérieurs ne doivent jamais écrire de contenu médical ni de motif
    d'absence détaillé dans le champ libre `reason` ou `context` : les clés
    sensibles sont expurgées, mais un texte libre ne peut pas être filtré de
    façon fiable.
    Limite : un acteur disposant de la clé d'audit et d'un accès écriture SQL
    direct peut recalculer la chaîne ; la séparation des accès et la supervision
    restent nécessaires. La rotation d'`APP_KEY` rend non vérifiables les
    événements historiques qui en dépendent.
-   **Auto-validation** : `ApprovalRules` interdit à un conducteur/chef
    d'approuver sa propre proposition ; l'auto-validation Bureau/Direction
    exige un motif et produit un événement d'audit dédié.
-   **Configuration** : `config/planning.php` (durée d'activation, durée max
    des exceptions, clé et identifiant d'audit, délai du verrou salarié),
    `config/session.php` (15 minutes).

### 3.2 Salariés, emploi et équipes (lot 3)

-   **Séparation compte / salarié** : `employees` est indépendant de `users`.
    `employees.user_id` est nullable, unique et `nullOnDelete` ; une fiche peut
    exister sans compte et la désactivation ou la suppression technique du
    compte ne supprime ni la fiche ni son historique.
-   **Identité minimale** : `first_name`, `last_name`. Les champs non validés
    (matricule, coordonnées, date de naissance, contrat, poste) ne sont pas
    inventés dans ce lot.
-   **Périodes d'emploi** : `employment_periods` (`employee_id`, `start_date`,
    `end_date` nullable), dates inclusives, contrainte SQL de cohérence et
    index. Les périodes sont strictement append-only ; une réembauche crée une
    nouvelle période. Le modèle `EmploymentPeriod` refuse toute mise à jour
    Eloquent directe et toute suppression, sans API d'autorisation temporaire.
    La clôture légitime est écrite uniquement dans
    `EmployeeLifecycleService::exit()`, par une écriture Query Builder ciblée
    (`whereNull('end_date')`) après permission, verrou salarié, relecture et
    contrôle des affectations/responsabilités, sous transaction ; le résultat
    `$affected !== 1` provoque une exception et le rollback complet (écriture
    SQL directe hors application possible, non couverte par les modèles).
    L'absence de chevauchement est vérifiée par `EmploymentPeriodService` dans
    une transaction protégée par un verrou MySQL nommé (`EmployeeMutationLock`).
    MySQL ne fournit pas d'exclusion de plages : la contrainte SQL seule ne
    suffit pas. Aucune procédure de correction rétroactive n'est implémentée
    faute de règles métier ; voir `docs/OPERATIONS.md`.
-   **Équipes** : `teams` ne porte pas de `leader_id` écrasable. Les
    affectations sont dans `team_assignments` (`kind` = `primary` ou
    `temporary`) et les responsabilités dans `team_leaderships`. Ces tables
    sont historisées, sans suppression en cascade ; une affectation future peut
    être annulée avec motif (`cancelled_at`, `cancellation_reason`), une
    affectation commencée se clôt par une date de fin.
-   **Cohérence** : une affectation ou une responsabilité doit être entièrement
    couverte par une période d'emploi. Une affectation temporaire est un
    enregistrement distinct et ne modifie jamais l'équipe principale. Une
    équipe ne peut avoir qu'une responsabilité principale à la fois, et un
    salarié ne peut être responsable principal que d'une seule équipe à la fois,
    toutes équipes confondues.
-   **Responsable principal** : salarié couvert par une période d'emploi,
    compte actif lié, rôle `chef_equipe` ou `conducteur_travaux`. La
    réaffectation est explicite : l'ancienne responsabilité ouverte est
    clôturée la veille de la date d'effet et l'historique est conservé. Le
    retrait du rôle, la désactivation du compte et la déliaison du compte sont
    refusés tant qu'une responsabilité non terminée subsiste.
-   **Concurrence** : les opérations de responsabilité prennent d'abord un
    verrou nommé sur l'équipe (`TeamMutationLock`), puis les verrous salariés
    (`EmployeeMutationLock::runMany`) triés par identifiant. L'administration
    des comptes prend le verrou salarié avant de verrouiller la ligne `users`.
    Les règles sont revalidées après acquisition des verrous ; une attente
    dépassée refuse l'écriture.
-   **Sortie** : `EmployeeLifecycleService` clôt la période d'emploi ouverte.
    Avant écriture, il liste les affectations et responsabilités qui
    resteraient actives après la date de sortie et refuse l'opération avec
    `EmployeeExitBlockedException`. Aucune affectation n'est tronquée
    automatiquement. Le même service peut, sur demande explicite, désactiver le
    compte applicatif lié dans la même transaction : la sortie, la révocation
    des sessions, la désactivation et leurs audits sont alors atomiques. Sans
    demande explicite, le compte reste inchangé. Une désactivation immédiate
    n'est acceptée que pour une sortie effective aujourd'hui ou dans le passé.
    Elle est refusée si une responsabilité d'équipe couvre encore la date
    effective de désactivation (dates inclusives : end_date nulle ou >=
    aujourd'hui, y compris une responsabilité se terminant à la date de
    sortie) ; la sortie reste alors possible sans désactivation, la
    responsabilité étant compatible avec la clôture de la période.
-   **Permissions** : `employees.view`, `employees.manage`, `teams.view`,
    `teams.manage`, accordées uniquement au Bureau/Direction. L'Administrateur
    technique conserve uniquement `audit.view` ; les autres rôles n'ont pas
    accès au panneau. Les services revérifient la permission de l'acteur.
-   **Audit** : actions `employee.*`, `employment_period.*`,
    `team.*`, `team_assignment.*`, `team_leader.*` dans le journal existant.
    Les noms de salariés ne sont pas recopiés dans les modifications d'audit ;
    les identifiants, dates, équipes et natures suffisent.
-   **Planning chantier (PC1-29)** : `planning_assignments` référence un
    chantier, une équipe, une date civile et une demi-journée
    (`App\Enums\PlanningHalfDay`). Les membres de l'équipe ne sont pas
    recopiés : `PlanningRoster` les déduit des `team_assignments` actives à la
    date, principales ou temporaires. Les exclusions individuelles sont dans
    `planning_assignment_exclusions` et ne créent ni absence ni temps réel.
    `PlanningAssignmentService` centralise la validation, les exclusions, les
    chevauchements d'équipe ou de salarié et l'audit transactionnel. Les
    chevauchements sont des avertissements non bloquants conservés dans le
    contexte d'audit ; aucune affectation existante n'est déplacée. La
    suppression physique est refusée ; l'historique complet reste à définir
    dans PC1-30.

Ne pas installer, mettre à jour ou remplacer une dépendance majeure sans
examiner sa compatibilité et justifier le changement. Les versions
effectives doivent être verrouillées dans `composer.lock` et
documentées. La CI utilise PHP 8.4, Node 22.18.0 et MySQL 8.4 ; la base
locale de développement peut utiliser une version MySQL 8.x compatible.

## 3. Architecture applicative proposée

Pour le MVP, privilégier un monolithe modulaire plutôt que des
microservices. L'application et la base restent autonomes.

### Couches recommandées

-   **Interface** : ressources/pages Filament pour les opérations de
    bureau ; interfaces Livewire adaptées au mobile pour les salariés et
    responsables.
-   **Autorisation** : policies/gates et règles d'accès réutilisables,
    appliquées sur chaque action côté serveur.
-   **Application** : actions ou services dédiés aux cas d'usage
    (soumettre un relevé, contrôler, valider, clôturer un mois, générer
    un export, synchroniser une saisie, traiter un signalement).
-   **Domaine métier** : règles horaires, compteur, transitions de
    workflow et invariants.
-   **Persistance** : modèles Eloquent, migrations, contraintes, index
    et transactions.
-   **Infrastructure** : notifications, génération d'exports, tâches en
    arrière-plan, stockage local côté client et synchronisation.
-   **Qualité** : tests Pest, formatage Pint et CI.

Cette séparation est une recommandation d'implémentation ; elle ne doit
pas devenir une abstraction excessive. Suivre les conventions réelles du
dépôt.

## 4. Modules fonctionnels

Modules logiques proposés : 1. Utilisateurs, authentification et rôles.
2. Salariés et périodes d'emploi. 3. Équipes et historique des
affectations. 4. Clients et chantiers. 5. Planning et affectations par
demi-journée. 6. Relevés d'heures et workflow. 7. Absences. 8.
Références mensuelles et compteur d'heures. 9. Indemnités et avances.
10. Clôtures mensuelles/trimestrielles. 11. Exports RH et versionnement.
12. Notifications. 13. Signalements. 14. Synchronisation PWA et
conflits. 15. Journalisation et audit.

Ces modules peuvent être des espaces de code au sein d'un seul projet
Laravel. Le découpage en packages Composer séparés n'est pas requis pour
le MVP.

## 5. Modèle de données --- principes

Le modèle relationnel exact doit être établi avant les migrations
initiales. Il doit notamment permettre : - une fiche salarié persistante
avec plusieurs périodes d'emploi ; - une équipe principale et des
affectations datées ; - des chantiers archivables ; - des affectations
de planning distinctes des heures réelles ; - des relevés et segments
horaires ; - des transitions et historiques de validation ; - des
événements d'absence distincts des segments ; - des références
mensuelles historisées ; - un journal de mouvements du compteur ; - des
lignes d'indemnités conservant les taux appliqués ; - des avances
historisées ; - des clôtures de période ; - des exports versionnés ; -
des transmissions hors ligne idempotentes et des conflits ; - des
signalements, messages, changements de statut et liens de doublons.

### Intégrité des données

-   Utiliser des clés étrangères, contraintes d'unicité et index
    pertinents.
-   Utiliser des transactions pour les opérations qui modifient
    plusieurs enregistrements liés.
-   Conserver les mouvements financiers/heures historiques au lieu de
    modifier leur passé silencieusement.
-   Utiliser des timestamps explicites pour les opérations sensibles.
-   Éviter de représenter des durées calculées comme des valeurs
    manuellement éditables sans contrôle.
-   Définir les états de workflow avec transitions autorisées
    explicites.
-   Les suppressions physiques de données métier ne doivent pas
    contourner les règles de conservation.

Le schéma précis, les noms de tables et les cardinalités doivent être
conçus et testés avant implémentation ; ce document ne les invente pas.

## 6. Calculs et temps

-   Utiliser une précision à la minute et éviter les arrondis
    intermédiaires.
-   Stocker les instants et interpréter les périodes dans le fuseau
    métier français, à confirmer techniquement dans la configuration.
-   Gérer les segments qui traversent minuit et les semaines/mois qui se
    chevauchent.
-   Centraliser les formules de calcul dans des classes ou services
    testables.
-   Séparer calcul hebdomadaire des heures au-delà de 39 h et compteur
    mensuel basé uniquement sur le travail effectif.
-   Ne pas calculer de taux de majoration, de montant de paie ni de
    retenue.
-   Les ajustements de référence mensuelle doivent être historisés et ne
    doivent pas recalculer silencieusement des périodes validées.

Les tests doivent contenir des exemples chiffrés explicites pour les
semaines de frontière, les segments traversant minuit, les minutes, les
déficits et les changements de référence.

## 7. Workflow et audit

Les opérations de soumission, retour en correction, contrôle,
validation, clôture et réouverture doivent passer par des actions métier
explicites. Éviter les mises à jour arbitraires du statut depuis une
interface.

L'audit doit conserver au minimum, selon l'opération : acteur,
date/heure, action, objet concerné, ancien/nouveau statut, motif requis
et référence de la période. Ne pas stocker de secrets ou de détails
personnels non nécessaires dans les journaux.

Les opérations groupées du chef d'équipe doivent produire une trace
distincte par relevé.

### 7.1 Chantiers et clients (PC1-28)

Le référentiel des statuts de chantier et la matrice des transitions sont
centralisés dans `App\Enums\SiteStatus`. La colonne `sites.status` reste une
chaîne courte ; les valeurs historiques connues sont normalisées par migration.
Toute transition ordinaire ou réouverture passe par `App\Services\Sites\SiteService`,
qui vérifie la matrice, les motifs, les permissions et écrit l'audit dans la même
transaction. Les formulaires Filament ne permettent pas de saisir un statut
libre.

L'archivage d'un client est conditionné à l'absence de chantier non terminal ;
les chantiers archivés sont inclus dans le contrôle. L'archivage et la
restauration d'un client sont indépendants de l'archivage des chantiers. La
restauration exige la permission dédiée `clients.restore`, distincte de
`clients.manage`. La réouverture d'un chantier exige `sites.reopen`, distincte de
`sites.manage`.

Les clients et les chantiers ne sont jamais supprimés physiquement : les
policies refusent la suppression et les modèles bloquent l'événement `deleting`.
L'archivage est le seul mécanisme de retrait.

Les adresses clients sont structurées ; le champ historique `address` est
conservé sans découpe automatique. Les doublons de raison sociale ou d'e-mail
sont signalés après enregistrement, sans contrainte d'unicité ni blocage.

## 8. PWA et synchronisation

### 8.1 Séparation client/serveur

Le serveur reste la source de vérité pour les données confirmées. Le
client peut conserver des brouillons et une file de synchronisation
locale. Les états d'interface doivent distinguer clairement les données
locales et les données confirmées par le serveur.

### 8.2 Transmission idempotente

Chaque opération de synchronisation doit avoir un identifiant unique
stable. Le serveur doit détecter une opération déjà traitée et renvoyer
un résultat cohérent plutôt que créer un doublon.

### 8.3 Conflits

Les modifications locales et serveur doivent être comparées à l'aide
d'identifiants et de versions de données. La stratégie exacte de
versionnement et de résolution doit être spécifiée avant
l'implémentation. Aucune stratégie « dernier enregistrement gagnant » ne
doit écraser silencieusement une modification.

### 8.4 Stockage local protégé

Avant d'implémenter le stockage hors ligne, documenter : - les données
qui doivent réellement être disponibles hors ligne ; - le mécanisme de
chiffrement et la gestion des clés ; - l'isolation par compte ; - le
comportement lors d'une déconnexion ; - la conservation des données non
synchronisées ; - le comportement si le stockage est plein, indisponible
ou corrompu.

Le choix précis de technologie de stockage et de chiffrement doit être
validé à la lumière des capacités des navigateurs ciblés. Ne pas
prétendre qu'une PWA peut garantir un effacement sécurisé dans tous les
navigateurs/appareils sans vérification.

## 9. Notifications et tâches en arrière-plan

Les notifications de planning, rappels de relevés et événements de
signalement doivent utiliser un mécanisme cohérent et être testables.
Les tâches planifiées ne doivent pas envoyer de rappels injustifiés en
cas d'absence enregistrée ou de journée sans saisie attendue.

La configuration du scheduler, des queues et des transports e-mail doit
être documentée selon l'hébergement retenu. Ne pas supposer que les
workers ou tâches planifiées sont déjà configurés.

## 10. Exports

Les exports doivent être générés depuis des données validées et
autorisées. Le contrôle d'accès s'applique également aux
téléchargements.

Chaque export conserve période, date de génération, version et lien vers
la version remplacée le cas échéant. Les anciennes versions restent
disponibles selon les droits et la politique de conservation. Les
formats Excel/PDF et leur contenu doivent être couverts par des tests.

## 11. Sécurité

-   Authentification et 2FA pour les rôles requis par la spécification.
-   Protection des sessions et expiration après 15 minutes d'inactivité.
-   Limitation de débit sur les endpoints sensibles.
-   Validation côté serveur de toutes les entrées.
-   Protection CSRF et paramètres de session sécurisés.
-   Autorisation sur les téléchargements, actions Livewire, APIs et
    tâches en arrière-plan.
-   Secrets exclusivement dans la configuration de
    déploiement/gestionnaire de secrets, jamais dans Git.
-   Journaux sans mots de passe, tokens ou données RH superflues.
-   Tests explicites des accès autorisés et refusés.
-   Sauvegardes chiffrées et séparées de la production.

## 12. Tests et intégration continue

La suite de tests doit couvrir les calculs horaires, frontières de
dates, permissions, transitions de workflow, clôtures, compteurs,
historique des taux, exports/versionnement, signalements et
synchronisation hors ligne.

Objectif progressif de couverture globale : 70 %, puis 80 %. Ce
pourcentage ne remplace pas les tests dédiés des fonctions critiques.

La CI doit exécuter les commandes réellement configurées dans le dépôt.
Les pull requests ne peuvent être fusionnées si les contrôles
obligatoires échouent.

## 13. Déploiement, sauvegardes et reprise

Environnements distincts : développement, préproduction et production.
Le déploiement de production est manuel après validation de
préproduction.

Sauvegardes serveur toutes les six heures, chiffrées et séparées de la
production. Conservation retenue : 7 jours de sauvegardes quotidiennes
et 4 sauvegardes hebdomadaires supplémentaires. La rotation doit
préserver ces sauvegardes hebdomadaires. La restauration doit être
testée périodiquement.

Les objectifs de reprise, la fréquence précise des tests, la
supervision, l'hébergement, les migrations et la procédure de retour
arrière restent à définir.

## 14. Points ouverts à résoudre avant production

1.  Confirmer les règles sociales et d'indemnités avec le gestionnaire
    de paie.
2.  Définir les durées de conservation légales et opérationnelles.
3.  Choisir et valider le stockage/chiffrement local et la gestion des
    clés.
4.  Définir la stratégie détaillée de résolution des conflits.
5.  Définir RPO/RTO, fréquence des tests de restauration et rotation
    exacte.
6.  Fixer l'hébergement, le domaine, la supervision et les paramètres de
    déploiement.
7.  Vérifier compatibilité navigateurs/appareils et comportement en cas
    de stockage local saturé.
8.  Finaliser les transitions exactes des workflows et les cas de
    réouverture.
9.  Vérifier les versions réellement disponibles et compatibles des
    dépendances avant l'installation.

## 15. Critères de fin de tâche

Une tâche est terminée lorsque la fonctionnalité correspond à la
spécification, que les permissions et validations sont imposées côté
serveur, que les tests pertinents sont ajoutés et exécutés, que le diff
est relu, que la documentation est à jour et que les vérifications non
réalisées sont explicitement signalées.

Ne jamais affirmer qu'un test a réussi, qu'un fichier existe, qu'une
migration a été exécutée ou qu'un déploiement a abouti sans
vérification.
