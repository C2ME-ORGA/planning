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
- crée un compte sans mot de passe utilisable (mot de passe aléatoire jamais
  communiqué) ;
- envoie un lien d'activation à usage unique dont la durée est fixée par
  `PLANNING_ACTIVATION_TTL_MINUTES` (1440 minutes par défaut, **valeur non
  encore validée par le métier**) ;
- journalise l'opération avec un acteur `null` (système).

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
discontinue, si un `previous_hash` ne correspond pas, si une signature est
invalide ou si la clé d'un événement est inconnue. Elle ne répare rien : en cas
d'échec, alerter, isoler la base et restaurer depuis une sauvegarde vérifiée.

L'Administrateur peut consulter `/admin/audit-events`, en lecture seule.

**Limite connue :** une personne disposant à la fois de la clé d'audit et d'un
accès écriture direct à la base peut recalculer la chaîne. Les triggers MySQL
bloquent `UPDATE` et `DELETE`, mais ne protègent pas contre un acteur disposant
de ces deux secrets. La séparation des accès, la supervision et les sauvegardes
sont indispensables. La rotation d'`APP_KEY` invalide en revanche la
vérification des événements historiques qui en dépendent : elle doit être
planifiée après la disparition de ces événements ou après ré-ancrage documenté.

## 6. Urgence et révocation de session

```bash
php artisan planning:emergency-lockdown --confirm
```

Révoque toutes les sessions et jetons persistants. La commande **n'accorde
aucun droit** et ne constitue pas un break-glass. Les utilisateurs doivent se
réauthentifier, 2FA comprise.

## 7. Vérifications avant mise en service

- `php artisan migrate --pretend` relu, puis migration contrôlée.
- `php artisan planning:audit:verify` sur une base vierge puis après tests.
- `php artisan test`, `composer lint:check`, `composer types:check` verts.
- `APP_KEY` défini et sauvegardé séparément ; `PLANNING_AUDIT_KEY` distincte et
  sauvegardée séparément ; `APP_DEBUG=false` ;
  `SESSION_SECURE_COOKIE=true` en HTTPS.
- Aucun compte de test, aucune donnée réelle, aucun secret dans le dépôt.
- Les triggers d'audit sont présents (`SHOW TRIGGERS`).
- Au moins un compte Bureau/Direction actif et un second facteur configuré.

## 8. Points non couverts par le lot 2

- Définition formelle des règles S01 à S03 et des arbitrages Q1 à Q5 : absents
  du dépôt, interprétés de façon conservatrice.
- Politique de conservation, sauvegardes chiffrées, supervision et procédure
  de restauration : lot 11.
- Validation métier de la durée d'activation (1440 minutes par défaut).
- Aucun module de planning, de relevé ou d'absence n'est encore implémenté ;
  les permissions correspondantes sont posées mais non exercées par une UI.

## 9. Points non couverts par le lot 3

- Champs RH non explicitement spécifiés (matricule, coordonnées, date de
  naissance, contrat, poste) : non créés.
- Les périodes d'emploi sont append-only dans l'interface ; une correction
  rétroactive n'est pas proposée. Une réembauche ajoute une période.
- La sortie ne désactive pas automatiquement le compte applicatif : la
  désactivation reste une opération distincte du lot 2, bloquée tant qu'une
  responsabilité d'équipe reste active.
- Une affectation déjà commencée ne peut pas être annulée ; elle doit être
  clôturée à une date de fin. Une affectation future peut être annulée avec
  motif et reste conservée dans l'historique.
- Le verrou salarié utilise `PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS` (5 secondes
  par défaut). Une attente dépassée refuse l'écriture, elle ne la valide pas.
- Le lot 3 attend la validation humaine avant toute suite.
