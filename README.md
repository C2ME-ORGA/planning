# Planning C2ME

Application Laravel indépendante pour la gestion du planning et des relevés
d'heures de SARL C2ME. Le dépôt couvre le socle technique (lot 0),
l'authentification, les rôles et l'audit (lot 2), ainsi que les salariés,
périodes d'emploi et équipes (lot 3). Les chantiers, le planning, les relevés,
les compteurs et les exports ne sont pas encore implémentés.

## Socle technique

- PHP 8.4
- Laravel 13
- Livewire 4
- Filament 5 pour le panneau d'administration
- MySQL 8 pour tous les environnements
- Pest, Laravel Pint et Larastan/PHPStan
- Tailwind CSS et Vite Plus
- GitHub Actions pour la CI

## Prérequis

- PHP 8.4 avec les extensions `pdo_mysql`, `intl`, `mbstring`, `gd` et `zip`
- Composer 2
- Node.js 22.18.0 ou une version compatible (`^22.18.0 || ^24.11.0 || >=26.0.0`)
- MySQL 8

Avec nvm : `nvm install` puis `nvm use` à la racine du dépôt.

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Sous PowerShell, remplacer `cp` par `Copy-Item`.

Créer deux bases MySQL distinctes : une pour le développement et une pour les
tests automatisés.

```sql
CREATE DATABASE planning_c2me
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE planning_c2me_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Renseigner ensuite dans `.env` les paramètres `DB_*`, puis exécuter :

```bash
php artisan migrate
npm ci
npm run build
php artisan filament:assets
```

L'application est servie par Herd à l'adresse `https://planning.c2me.test/`.
Adapter `APP_URL` si un autre nom d'hôte est utilisé.

## Panneau d'administration

Le panneau Filament est disponible sur `/admin`. L'accès dépend exclusivement du
rôle du compte, contrôlé côté serveur (`canAccessPanel()`), et non plus d'une
liste d'adresses. Seuls les rôles **Administrateur** et **Bureau/Direction**
ouvrent le panneau ; les autres rôles n'ont aucun accès à l'interface
d'administration.

La double authentification TOTP (avec codes de récupération) est obligatoire
pour tous les comptes du panneau. Un compte sans second facteur est redirigé
vers la configuration avant d'atteindre une page.

### Premier compte Bureau / Direction

Aucune inscription libre n'existe. Le tout premier compte se crée uniquement en
ligne de commande, sur un serveur maîtrisé, puis le lien d'activation est
transmis par un canal sûr :

```bash
php artisan planning:bootstrap-bureau direction@example.test \
    --name="Direction C2ME" \
    --confirm
```

La commande refuse de s'exécuter si un compte Bureau/Direction existe déjà ou
si l'adresse est déjà utilisée. Elle crée un compte sans mot de passe utilisable
(mot de passe aléatoire jamais communiqué), envoie un lien d'activation à usage
unique et journalise l'opération avec un acteur système. Les comptes suivants
sont créés par le Bureau/Direction depuis `/admin/users` ; l'Administrateur
technique ne peut ni créer de compte ni s'attribuer le rôle Bureau/Direction.

Variables utiles :

```dotenv
# Durée du lien d'activation (minutes). Valeur par défaut à valider.
PLANNING_ACTIVATION_TTL_MINUTES=1440
# Durée maximale d'une exception de permission (mois).
PLANNING_EXCEPTION_MAX_MONTHS=12
# Délai d'attente du verrou ciblé sur les écritures salarié (secondes).
PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS=5
# Clé dédiée au journal d'audit, distincte d'APP_KEY (32 caractères minimum).
PLANNING_AUDIT_KEY_ID=audit-v1
PLANNING_AUDIT_KEY=
```

La clé d'audit se génère avec `php artisan key:generate --show`, puis se stocke
dans le gestionnaire de secrets. `planning:audit:verify` refuse une clé absente,
trop courte ou identique à `APP_KEY`.

### Exploitation

```bash
# Vérifier l'intégrité de la chaîne d'audit
php artisan planning:audit:verify

# Révoquer toutes les sessions (urgence, n'accorde aucun droit)
php artisan planning:emergency-lockdown --confirm

# Réinitialiser la 2FA d'un compte après perte d'accès
php artisan planning:mfa:reset agent@example.test \
    --actor=direction@example.test --reason="Perte de téléphone" --confirm
```

Les exceptions de permission sont accordées et révoquées uniquement en ligne de
commande, avec acteur Bureau/Direction, motif d'au moins 10 caractères et
échéance. Une exception ne peut jamais porter sur la gestion des comptes, de
l'audit, des absences, ni sur la validation, la correction ou la réouverture.

### Salariés et équipes

Le lot 3 ajoute deux écrans réservés au Bureau/Direction :

- `/admin/employees` : fiche salarié, périodes d'emploi, compte applicatif
  facultatif, affectations et responsabilités ;
- `/admin/teams` : équipes, affectations historisées et responsables principaux.

Une fiche salarié peut exister sans compte. Un compte ne peut être lié qu'à un
seul salarié. Les périodes d'emploi sont inclusives, ne se chevauchent jamais
et ne sont pas écrasées : une réembauche ajoute une nouvelle période. Une
sortie est refusée tant qu'une affectation ou une responsabilité resterait
active après la date de sortie ; l'utilisateur doit la clôturer ou l'annuler
explicitement. La sortie peut proposer, via une option explicite et confirmée,
la désactivation immédiate du compte applicatif lié ; sans cette option, le
compte reste inchangé. Les affectations temporaires ne modifient jamais
l'équipe principale. Un responsable principal doit être couvert par une
période d'emploi, disposer d'un compte actif, avoir le rôle Chef d'équipe ou
Conducteur de travaux et ne diriger qu'une seule équipe à la fois.

Aucun élément RH ne peut être supprimé depuis l'interface. Les périodes
d'emploi sont append-only au niveau du modèle ; aucune procédure de correction
rétroactive n'est encore définie. Les écritures sensibles sont sérialisées par
des verrous MySQL ciblés sur l'équipe puis sur les salariés concernés, dans un
ordre déterministe ; le délai d'attente est configurable avec
`PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS`.

Le guide d'exploitation détaillé se trouve dans `docs/OPERATIONS.md`.

## Tests

Les tests utilisent MySQL et une base dédiée, `planning_c2me_test`, définie dans
`phpunit.xml`. `RefreshDatabase` réinitialise uniquement cette base. Ne jamais
pointer la configuration de test vers une base de développement ou de
production.

```bash
php artisan test
composer test
composer test:coverage
```

`composer test:coverage` applique un seuil progressif de 35 %. L'objectif de
70 % puis 80 % reste la cible du projet et ne remplace pas les tests spécifiques
des fonctions critiques.

## Qualité

```bash
composer lint          # Pint, correction
composer lint:check    # Pint, vérification
composer types:check   # PHPStan/Larastan, mémoire limitée à 1 Go
composer ci:check      # Pint + PHPStan + tests avec couverture
npm run build          # compilation front-end
```

PHPStan est lancé avec une limite mémoire explicite de 1 Go dans le script
Composer `types:check`, afin de ne pas dépendre d'un cache local ni de la
limite mémoire par défaut de PHP.

## Front-end

```bash
npm ci
npm run build
```

Le projet utilise `vite-plus` via un alias npm vers
`@voidzero-dev/vite-plus-core`, conformément au starter kit. La version Node est
verrouillée dans `.nvmrc` et dans la CI. Le build peut afficher un avertissement
non bloquant concernant le paquet optionnel `fontaine` (optimisation des
polices) ; il n'empêche pas la compilation.

## Branches et contribution

La stratégie cible est :

```text
develop -> Pull Request -> master -> Pull Request -> production
```

- Ne pas pousser directement sur `production`.
- Ouvrir une pull request et attendre la CI verte.
- Ne pas fusionner une pull request dont un contrôle obligatoire échoue.
- Ne pas déployer automatiquement en production.

Les branches `develop`, `master` et `production` sont protégées sur GitHub :
pull request obligatoire, contrôle `ci` requis, force-push et suppression
interdits. `production` applique également ces règles aux administrateurs. La
branche `develop` est publiée et son premier run CI est vert.

La CI ne déploie rien : elle exécute uniquement les contrôles de qualité.
- Aucune fonctionnalité métier ne doit être ajoutée avant validation de la
  modélisation et des décisions d'architecture.

## Seeders

`DatabaseSeeder` crée un utilisateur de démonstration uniquement dans les
environnements `local` et `testing`. Il est ignoré dans les autres
environnements afin d'éviter la création d'un compte au mot de passe connu en
production. Ne jamais exécuter de seeder de démonstration sur une base réelle.

## Configuration de production

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` avec le domaine HTTPS réel
- `SESSION_SECURE_COOKIE=true` (valeur par défaut en production)
- Identifiants MySQL dédiés, jamais `root`
- Secrets uniquement dans l'environnement de déploiement

Les sauvegardes, la supervision, la restauration et la procédure de déploiement
restent à définir au lot 11. Servir `public/` comme racine web et exécuter
`php artisan filament:assets` lors du déploiement si les assets publiés ne sont
pas présents.

La licence du projet n'est pas encore déterminée. Aucun fichier `LICENSE`
n'est fourni et aucune licence n'est déclarée dans `composer.json` en attendant
la décision juridique de l'entreprise.

## Documentation

- `AGENTS.md` : consignes de développement
- `ROADMAP.md` : lots et points d'arrêt
- `docs/SPECIFICATIONS.md` : spécifications fonctionnelles
- `docs/ARCHITECTURE.md` : architecture technique
- `docs/SECURITY.md` : exigences de sécurité
- `docs/TESTING.md` : stratégie de tests

Les ADR numérotées 001 à 151 ne sont pas présentes dans ce dépôt. Leur source
originale n'a pas été retrouvée : ne pas les inventer, les reconstituer ou les
renuméroter avant décision explicite.
