# Planning C2ME — Guide d'exploitation du lot 2

Ce guide couvre l'exploitation courante des comptes, de la double
authentification et du journal d'audit. Il ne vaut pas autorisation de mise en
production : voir `ROADMAP.md` et `docs/SECURITY.md`.

## 1. Amorçage du premier compte Bureau / Direction

À exécuter une seule fois, sur un serveur maîtrisé, avant toute ouverture du
panneau :

```bash
php artisan planning:bootstrap-bureau direction@example.test \
    --name="Direction C2ME" \
    --confirm
```

Comportement :

- refuse si un compte Bureau/Direction existe déjà ;
- refuse si l'adresse e-mail est déjà utilisée ;
- réserve le bootstrap de façon atomique via la clé primaire de
  `system_locks` : deux exécutions concurrentes ne peuvent pas créer deux
  premiers comptes. Le marqueur reste en base après succès et ferme
  définitivement le bootstrap ;
- crée un compte sans mot de passe utilisable (mot de passe aléatoire jamais
  communiqué) ;
- envoie un lien d'activation à usage unique dont la durée est fixée par
  `PLANNING_ACTIVATION_TTL_MINUTES` (1440 minutes, soit 24 heures, **valeur
  validée par le métier**) ;
- journalise l'opération avec un acteur `null` (système) ;
- envoie la notification uniquement après le commit effectif de la transaction
  englobante (`DB::afterCommit`). Un échec du transport e-mail après commit est
  journalisé sans le jeton ; l'activation reste valide et l'opération n'est pas
  annulée.

Le lien doit être transmis par un canal sûr. Ne jamais le journaliser ni le
coller dans un ticket.

Après activation, la 2FA TOTP est exigée à la première connexion.

### Création d'un compte Bureau / Direction supplémentaire

Le mode initial ci-dessus reste réservé au premier compte. Pour créer un compte
supplémentaire (notamment un compte suppléant), un compte Bureau / Direction
actif doit autoriser l'opération :

```bash
php artisan planning:bootstrap-bureau suppleant@example.test \
    --name="Nom du suppléant" \
    --additional \
    --actor=direction@example.test \
    --confirm
```

Règles :

- l'acteur doit être un compte actif ayant les capacités de création de compte
  et d'attribution de rôle, et son rôle doit être Bureau / Direction ;
- l'adresse e-mail doit être unique ; aucun compte existant n'est promu ;
- le nouveau compte reçoit le rôle Bureau / Direction standard, sans privilège
  supplémentaire ;
- la création et son acteur sont inscrits au journal d'audit ;
- un lien d'activation à usage unique est envoyé ; le titulaire définit lui-même
  son mot de passe ;
- la 2FA TOTP reste obligatoire à la première connexion.

Ne jamais partager les identifiants du titulaire principal ou du suppléant.

## 2. Gestion des comptes

- Création, changement de rôle, désactivation et réactivation : panneau
  `/admin/users`, réservé au Bureau/Direction.
- Un compte n'est jamais supprimé. La désactivation conserve l'historique et
  révoque immédiatement les sessions et le jeton « remember me ».
- Le dernier compte Bureau/Direction actif ne peut pas être désactivé ni
  rétrogradé.
- Nul ne peut modifier son propre rôle ni se désactiver soi-même.
- L'Administrateur technique ne peut pas créer de compte, changer de rôle ni
  s'attribuer le rôle Bureau/Direction.
- Les opérations sensibles sont transactionnelles et verrouillent la ligne du
  compte concerné. Les changements de rôle et désactivations verrouillent aussi
  l'ensemble des comptes Bureau/Direction actifs, dans un ordre stable, afin de
  ne jamais retirer les deux derniers simultanément.
- La révocation logique est prioritaire et transactionnelle. Le nettoyage
  physique de la table `sessions` n'est tenté que si `SESSION_DRIVER=database`.
  Avec `file`, `redis`, `cookie`, `array` ou un autre pilote, il est
  explicitement non applicable et aucune requête SQL n'est émise ; la
  désactivation et la révocation restent effectives. Le filtre serveur
  `EnsureAccountIsActive` reste un garde-fou pour les sessions déjà ouvertes.
- La connexion utilisée est `SESSION_CONNECTION` lorsqu’elle est définie, sinon
  la connexion par défaut. La table ciblée est `SESSION_TABLE` (ou `sessions`).
- Une erreur pendant le nettoyage physique est journalisée (`report()` et
  `Log::warning`) sans annuler la révocation logique déjà acquise. Une erreur
  pendant l'invalidation logique est propagée : aucune purge n'est tentée.
- Avec une connexion de sessions distincte, la suppression des sessions et le
  renouvellement des remember tokens ne peuvent pas partager une transaction
  SQL unique : chaque opération est transactionnelle sur sa connexion.

## 3. Double authentification

La 2FA TOTP est obligatoire pour tous les comptes du panneau, avec codes de
récupération. En cas de perte d'accès :

```bash
php artisan planning:mfa:reset agent@example.test \
    --actor=direction@example.test \
    --reason="Perte de téléphone, identité vérifiée" \
    --confirm
```

La commande vérifie que l'acteur possède la permission de gestion des comptes,
journalise l'opération et force la reconfiguration du second facteur. Aucun
contournement de la 2FA n'existe.

## 4. Exceptions de permission

Les exceptions ne sont pas gérées dans l'interface ; elles passent par la CLI,
avec un acteur Bureau/Direction, un motif d'au moins 10 caractères et une
échéance.

```bash
php artisan planning:permission-exception:grant agent@example.test planning.propose \
    --actor=direction@example.test \
    --reason="Remplacement temporaire du conducteur" \
    --expires-at=2027-03-31 \
    --confirm

php artisan planning:permission-exception:revoke 42 \
    --actor=direction@example.test \
    --reason="Retour du titulaire" \
    --confirm

php artisan planning:permission-exceptions:review
```

Règles :

- permissions éligibles uniquement : `planning.propose`, `timesheet.control`,
  `availability.view-perimeter` ;
- jamais les comptes, l'audit, les absences, la validation, la correction ou la
  réouverture ;
- ni pour soi-même, ni pour l'Administrateur ;
- durée maximale de 12 mois, révocable à tout moment, à revoir annuellement ;
- une exception ne contourne jamais les règles S02/S03 (non définies dans le
  dépôt à ce jour) et ne remplace pas la 2FA.
- une création verrouille la ligne du compte bénéficiaire et revérifie le
  doublon actif : deux créations concurrentes identiques sont sérialisées ;
- une révocation verrouille la ligne de l'exception et revérifie son état :
  deux révocations concurrentes ne produisent qu'une seule révocation et un
  seul événement d'audit.

## 5. Journal d'audit

Toute opération sensible produit un événement `audit_events` : acteur, rôle,
action, objet, motif, contexte expurgé, horodatage, séquence et chaînage
HMAC-SHA256.

### 5.1 Clé d'audit dédiée

La chaîne est signée par une clé distincte d'`APP_KEY`, d'au moins 32
caractères :

```dotenv
PLANNING_AUDIT_KEY_ID=audit-v1
PLANNING_AUDIT_KEY=<secret de 32 caractères minimum>
PLANNING_AUDIT_PREVIOUS_KEYS={}
```

Générer une valeur avec `php artisan key:generate --show`, puis la stocker dans
le gestionnaire de secrets. Ne jamais réutiliser `APP_KEY` : la commande de
vérification refuse une clé identique, absente ou trop courte.

Chaque événement enregistre l'identifiant de sa clé. Les événements antérieurs
à l'introduction de la clé dédiée ont un identifiant nul et restent vérifiables
avec `APP_KEY`. Ils ne doivent pas être supprimés lors de la migration.

### 5.2 Rotation de la clé d'audit

1. Choisir un nouvel identifiant (`audit-v2`) et une nouvelle clé.
2. Déplacer l'ancienne clé dans les clés antérieures :
   `PLANNING_AUDIT_PREVIOUS_KEYS='{"audit-v1":"<ancienne clé>"}'`.
3. Configurer `PLANNING_AUDIT_KEY_ID=audit-v2` et `PLANNING_AUDIT_KEY=<nouvelle clé>`.
4. Lancer `php artisan planning:audit:verify` : tous les événements doivent
   rester valides.
5. Conserver l'ancienne clé aussi longtemps que ses événements doivent être
   vérifiables. Sa suppression rend ces événements non vérifiables.

### 5.3 Vérification

```bash
php artisan planning:audit:verify
```

La commande valide d'abord la configuration de la clé (présence, longueur,
distinction d'avec `APP_KEY`), puis renvoie un code d'échec si la séquence est
discontinue, dupliquée, si un `previous_hash` ne correspond pas, si une
signature est invalide ou si la clé d'un événement est inconnue. Elle ne répare
rien : en cas d'échec, alerter, isoler la base et restaurer depuis une
sauvegarde vérifiée.

Le parcours est paginé par `sequence`, jamais par `id` SQL, par lots de 500
événements : les identifiants SQL peuvent ne pas suivre l'ordre logique, et le
journal ne doit jamais être chargé intégralement en mémoire. Une vérification
concurrente d'écritures reste possible : chaque lot voit un état cohérent, une
écriture peut être incluse ou non, mais elle ne crée pas de fausse rupture de
chaîne.

L'Administrateur peut consulter `/admin/audit-events`, en lecture seule.

**Limite connue :** une personne disposant à la fois de la clé d'audit et d'un
accès écriture direct à la base peut recalculer la chaîne. Les triggers MySQL
bloquent `UPDATE` et `DELETE`, mais ne protègent ni contre `TRUNCATE`/`DROP
TABLE` ni contre un acteur disposant de ces deux secrets. Une vérification sur
un journal vide est valide : la détection d'un vidage complet exige un ancrage
externe (sauvegarde, supervision du volume et du dernier hash). La séparation
des accès, la supervision et les sauvegardes sont indispensables ; le compte
applicatif ne doit disposer ni de `DROP`, ni de `ALTER`, ni de `TRIGGER`. La
rotation d'`APP_KEY` invalide en revanche la vérification des événements
historiques qui en dépendent : elle doit être planifiée après la disparition de
ces événements ou après ré-ancrage documenté.

## 6. Urgence et révocation de session

```bash
php artisan planning:emergency-lockdown \
  --actor=bureau@example.test \
  --reason="Incident de sécurité : compromission suspectée." \
  --confirm
```

Révoque toutes les sessions et jetons persistants. La commande **n'accorde
aucun droit** et ne constitue pas un break-glass. Les utilisateurs doivent se
réauthentifier, 2FA comprise.

L’acteur doit être un compte actif disposant de `accounts.update` (Bureau /
Direction). Le motif est obligatoire (10 à 500 caractères). Un événement
`system.emergency_lockdown_requested` est écrit avant l’action. Si cet audit
d’intention échoue, la commande refuse de démarrer : aucune session n’est
révoquée. En environnement automatisé, l’acteur et le motif doivent donc être
fournis explicitement ; aucun acteur fictif n’est accepté.

La commande incrémente la génération globale d'authentification et invalide
tous les cookies remember-me dans une transaction unique. Dès ce commit, les
anciennes sessions sont refusées à la requête suivante, même si le nettoyage
physique échoue. La suppression SQL cible `session.connection` /
`session.table` et n'est requise que pour le pilote `database`.

Si le nettoyage physique échoue, la commande retourne un échec opérationnel,
mais l'audit `EmergencyLockdown` indique `fully_completed = false` avec la
génération globale active : la révocation logique reste effective. Vérifier
ensuite le stockage de sessions et relancer le nettoyage si nécessaire.

Les états de nettoyage sont explicites : `physical_cleanup_state` vaut
`completed`, `failed` ou `not_applicable`. `fully_completed` n'est faux que
pour un échec physique requis.

### Vérification après révocation globale

- L'audit `system.emergency_lockdown_requested` contient l’acteur et le motif.
- L'audit `system.emergency_lockdown` contient `global_generation`,
  `physical_cleanup_required`, `physical_cleanup_state`,
  `physical_cleanup_error` et `fully_completed`.
- Une ancienne session ou un ancien cookie remember-me doit être redirigé vers
  `/login` ou `/admin/login`.
- Une nouvelle connexion doit fonctionner pour un compte actif et rouvrir une
  session portant la nouvelle génération.

### Retour arrière interdit sur le schéma de sécurité

Les migrations `authentication_state` et `users.session_version` refusent leur
`down()` : supprimer la table réinitialiserait la génération globale et pourrait
rendre valides des sessions d’une génération antérieure ; supprimer la colonne
`session_version` désactiverait le contrôle de révocation individuelle. Un
retour arrière nécessite une intervention manuelle encadrée :

1. arrêter le code PC1-12 ou maintenir le contrôle de génération actif ;
2. révoquer toutes les sessions et tous les jetons remember-me ;
3. purger les stockages `file`/`redis`/`database` concernés ;
4. seulement ensuite, retirer la table selon la procédure d’exploitation.

Ne jamais exécuter `migrate:rollback` sur le batch de sécurité en production.

## 7. Vérifications avant mise en service

- Appliquer les migrations de sécurité **avant** les migrations métier sans
  rapport, afin que le code ne tourne jamais sans `authentication_state` ni
  `users.session_version` :

  ```bash
  php artisan planning:security:install
  php artisan planning:security:install --check
  ```

  La commande applique les deux migrations de sécurité dans des batches dédiés
  (une migration par batch : `authentication_state`, puis
  `users.session_version`) et vérifie le schéma.
  Elle est indépendante des migrations de feuilles de temps. Les migrations
  sont additives et ne modifient aucune donnée existante.
  `2026_10_27_000007` est volontairement antérieure aux migrations métier ;
  `2026_11_02_000001` est appliquée explicitement par la commande.
- `php artisan migrate --pretend` relu, puis migration contrôlée.
- `php artisan planning:audit:verify` sur une base vierge puis après tests.
- `php artisan test`, `composer lint:check`, `composer types:check` verts.
- `APP_KEY` défini et sauvegardé séparément ; `PLANNING_AUDIT_KEY` distincte et
  sauvegardée séparément ; `APP_DEBUG=false` ;
  `SESSION_SECURE_COOKIE=true` en HTTPS.
- `SESSION_DRIVER=database` : recommandé pour permettre le nettoyage physique
  des sessions ; sans ce pilote, la révocation logique reste effective et le
  nettoyage physique est simplement non applicable.
- Si `SESSION_CONNECTION` est défini, vérifier que cette connexion pointe vers
  la base contenant la table de sessions et que l’utilisateur applicatif y
  dispose des droits de lecture et de suppression.
- Aucun compte de test, aucune donnée réelle, aucun secret dans le dépôt.
- Les triggers d'audit sont présents (`SHOW TRIGGERS`).
- La table `system_locks` est migrée et le marqueur
  `bootstrap.bureau_direction` est absent avant le premier amorçage.
- Le transport e-mail réel est configuré et testé (`MAIL_MAILER`, hôte,
  identifiants, `MAIL_FROM_ADDRESS`) et `APP_URL` désigne l'URL publique :
  sans cela, les liens d'activation ne sont jamais reçus ou pointent vers le
  mauvais domaine, et l'amorçage reste inutilisable. `MAIL_MAILER=log` ne
  convient qu'au développement local.
- Au moins un compte Bureau/Direction actif et un second facteur configuré.

## 8. Points non couverts par le lot 2

- Définition formelle des règles S01 à S03 et des arbitrages Q1 à Q5 : absents
  du dépôt, interprétés de façon conservatrice.
- Politique de conservation, sauvegardes chiffrées, supervision et procédure
  de restauration : lot 11.
- Durée d'activation validée à 24 heures (1 440 minutes).
- État des modules métier dans l'arbre fusionné, à ne pas confondre avec une
  validation de production :
  - **présents dans le code et couverts par des tests** : RH et équipes (Lot 3),
    clients et chantiers (Lot 4), planning à la demi-journée et relevés/workflow
    (Lot 5) ;
  - **validés fonctionnellement** : authentification, rôles, permissions et
    audit du Lot 2 (validation acquise le 26/10/2026 sur `master`) ;
  - **en cours ou à confirmer** : recette complète des relevés (PC1-31), recette
    de la correction/réouverture (PC1-33), recette mobile du Lot 4 ;
  - **restant à livrer** : calculs et compteurs (Lot 6), absences et indemnités
    (Lot 7), clôtures et exports (Lot 8), notifications et signalements (Lot 9),
    PWA hors ligne (Lot 10), sauvegardes et production (Lot 11).
  Aucun de ces éléments n'est réputé déployé en production.

## 9. Points non couverts par le lot 3

- Champs RH non explicitement spécifiés (matricule, coordonnées, date de
  naissance, contrat, poste) : non créés.
- Les périodes d'emploi sont append-only dans l'interface et au niveau du
  modèle : seules la clôture d'une période ouverte et l'ajout d'une nouvelle
  période sont autorisés. Une réembauche ajoute une période.
- **Procédure de correction rétroactive absente** : aucune décision ne définit
  qui peut corriger, quels champs sont corrigeables, si la correction peut
  traverser des périodes clôturées, ni comment revalider les affectations et
  responsabilités dépendantes. Ne pas implémenter cette procédure sans
  arbitrage. Décisions minimales à obtenir : acteur habilité, motif obligatoire,
  champs et périodes concernés, conservation avant/après, effet sur les
  affectations, responsabilités et audits.
- La sortie propose une désactivation explicite du compte applicatif lié. Sans
  confirmation, le compte reste actif. Avec confirmation, la désactivation est
  immédiate et atomique avec la sortie et l'audit ; elle n'est acceptée que pour
  une sortie effective aujourd'hui ou dans le passé. Elle est refusée si une
  responsabilité d'équipe couvre encore la date effective de désactivation
  (dates inclusives, end_date >= aujourd'hui) : enregistrez la sortie sans
  désactivation, ou clôturez/réaffectez la responsabilité avant de réessayer.
- Une affectation déjà commencée ne peut pas être annulée ; elle doit être
  clôturée à une date de fin. Une affectation future peut être annulée avec
  motif et reste conservée dans l'historique.
- Les verrous nommés utilisent `PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS`
  (5 secondes par défaut). L'ordre est équipe puis salariés triés par
  identifiant. Une attente dépassée refuse l'écriture, elle ne la valide pas.
- Le lot 3 attend la validation humaine avant toute suite.


## 10. Connexion des utilisateurs terrain

Les ouvriers, chefs d'équipe et conducteurs de travaux utilisent `/login`, puis sont dirigés vers le planning mobile `/planning`. Le compte doit être actif et avoir terminé son activation. La connexion au panneau d'administration reste distincte sur `/admin/login` et demeure réservée aux rôles Administrateur et Bureau / Direction avec 2FA obligatoire.

En cas d'échec de connexion, vérifier l'adresse e-mail, le mot de passe et l'état d'activation du compte dans `/admin/users`. Ne pas promouvoir un compte terrain en Bureau / Direction pour contourner le contrôle d'accès.


## PC1-14 — Suppléance Bureau / Direction

La procédure détaillée est décrite dans `docs/PC1-14-PROCEDURE-URGENCE.md`.

- Suppléant : Corentin Doizy, Gérant, compte `c.doizy@c2me.ovh`.
- Compte nominatif permanent, rôle Bureau / Direction standard, identifiants et MFA propres.
- Corentin peut déclencher lui-même la suppléance lorsqu'il constate l'indisponibilité du titulaire principal ; aucun accord préalable n'est requis.
- La prise de relais s'effectue avec le compte propre du suppléant. Le partage d'identifiants et l'utilisation de la session du titulaire principal sont interdits.
- La suppléance n'est ni une élévation de privilèges ni un mécanisme break-glass.
- `planning:emergency-lockdown` reste une révocation globale des sessions, sans octroi de droits.
- Après chaque prise de relais exceptionnelle liée à un incident, une personne physique habilitée réalise une revue ; `direction@c2me.ovh` est le compte de référence.
- Contrôle trimestriel et après toute évolution importante de l'authentification, des rôles ou permissions, sous la responsabilité du titulaire habilité de `direction@c2me.ovh`, avec participation de Corentin.
- Aucun secret d'authentification ne doit figurer dans les preuves.

La validation formelle et les preuves de contrôle restent à enregistrer avant clôture du ticket.
