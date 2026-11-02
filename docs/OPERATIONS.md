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
- La révocation SQL exige `SESSION_DRIVER=database`. Tout autre pilote est
  refusé explicitement avant toute écriture : la désactivation ou la révocation
  échoue et sa transaction est annulée. Le filtre serveur
  `EnsureAccountIsActive` reste un garde-fou pour les sessions déjà ouvertes.
- La connexion utilisée est `SESSION_CONNECTION` lorsqu’elle est définie, sinon
  la connexion par défaut. La table et les colonnes `id`/`user_id` sont
  contrôlées sur cette connexion. Une connexion inconnue ou indisponible, une
  table absente ou incomplète provoquent un refus avant toute mutation.
- Avec une connexion de sessions distincte, la suppression des sessions et le
  renouvellement des remember tokens ne peuvent pas partager une transaction
  SQL unique : chaque opération est transactionnelle sur sa connexion, mais
  l’atomicité entre les deux connexions n’est pas garantie.

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
php artisan planning:emergency-lockdown --confirm
```

Révoque toutes les sessions et jetons persistants. La commande **n'accorde
aucun droit** et ne constitue pas un break-glass. Les utilisateurs doivent se
réauthentifier, 2FA comprise.

La configuration d'audit et le pilote de session sont validés avant toute
action. Seul `SESSION_DRIVER=database` est accepté : avec `file`, `redis` ou un
pilote non pris en charge, la commande échoue explicitement sans supprimer de
session et sans renouveler de remember token. La connexion utilisée est
`SESSION_CONNECTION` si elle est définie, sinon la connexion par défaut.

Avec la connexion par défaut, la révocation et l'événement d'audit sont dans une
transaction commune : si l'audit échoue, les sessions et remember tokens sont
restaurés. Avec une connexion de sessions distincte, la suppression des sessions
est validée sur cette connexion et ne peut pas être annulée par le rollback de
la transaction d'audit de la connexion par défaut. La commande ne doit jamais
annoncer une révocation réussie si elle a échoué.

Messages de retour :

- `Configuration d’audit invalide` : aucune action n’a été exécutée.
- `Opération non exécutée : aucune session n’a été supprimée` : échec avant
  mutation, notamment pilote, connexion, table ou colonnes invalides.
- `Révocation annulée : aucune session n’a été supprimée` : échec avec rollback
  sur la connexion par défaut.
- `Révocation partielle : N session(s) supprimée(s)` : des sessions ont été
  supprimées sur une connexion distincte, les remember tokens ont été renouvelés,
  mais l’audit n’a pas été enregistré, ou le renouvellement des tokens a échoué.
  Une vérification opérationnelle est obligatoire.
- `Révocation incomplète : aucune session n’a été supprimée` : aucune session
  n’existait sur la connexion dédiée ; les remember tokens ont été renouvelés,
  mais l’audit n’a pas été enregistré. Une vérification reste nécessaire.

Procédure de diagnostic après une `Révocation partielle` ou `incomplète` :

1. Identifier la connexion utilisée (`SESSION_CONNECTION` ou connexion par
   défaut).
2. Vérifier la présence de la table de sessions et des colonnes `id`/`user_id`
   sur cette connexion.
3. Compter les sessions restantes sur la connexion de sessions.
4. Vérifier les `remember_token` des comptes concernés.
5. Vérifier la présence de l’événement `EmergencyLockdown` dans le journal
   d’audit.
6. Consigner le résultat, ne pas relancer aveuglément, et déclencher une
   intervention si les sessions ou les jetons ne sont pas dans l’état attendu.

## 7. Vérifications avant mise en service

- `php artisan migrate --pretend` relu, puis migration contrôlée.
- `php artisan planning:audit:verify` sur une base vierge puis après tests.
- `php artisan test`, `composer lint:check`, `composer types:check` verts.
- `APP_KEY` défini et sauvegardé séparément ; `PLANNING_AUDIT_KEY` distincte et
  sauvegardée séparément ; `APP_DEBUG=false` ;
  `SESSION_SECURE_COOKIE=true` en HTTPS.
- `SESSION_DRIVER=database` : indispensable pour la révocation SQL des
  sessions et pour la désactivation immédiate des comptes.
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
- Aucun module de planning, de relevé ou d'absence n'est encore implémenté ;
  les permissions correspondantes sont posées mais non exercées par une UI.
