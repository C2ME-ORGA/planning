# Planning C2ME

Application Laravel indépendante pour la gestion du planning et des relevés
d'heures de SARL C2ME. Le dépôt est actuellement au socle technique du lot 0 :
aucune fonctionnalité métier (salariés, chantiers, relevés, exports) n'est
encore implémentée.

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

Le panneau Filament est disponible sur `/admin`.

L'accès est volontairement restreint à une liste d'adresses explicitement
autorisées par `PLANNING_ADMIN_EMAILS`, séparées par des virgules :

```dotenv
PLANNING_ADMIN_EMAILS=administrateur@example.test
```

Créer un utilisateur local avec :

```bash
php artisan make:filament-user
```

Puis ajouter son adresse à `PLANNING_ADMIN_EMAILS`. Une liste vide interdit
l'accès à tous. Ce mécanisme est temporaire : le modèle de rôles, la double
authentification et les policies seront implémentés au lot 2. Aucun panneau ne
doit être ouvert sans contrôle d'accès explicite.

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
