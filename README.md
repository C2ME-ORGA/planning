# Planning C2ME

Application Laravel indépendante de SARL C2ME pour la gestion des salariés, des équipes, des clients, des chantiers, du planning et des relevés d'heures.

> **État de référence :** branche `develop`, après fusion de la PR #15 le 1er novembre 2026. Cette branche constitue l'état de développement vérifié ici ; elle ne signifie pas que ces fonctionnalités sont déployées en production.

## État fonctionnel sur develop

| Domaine | État constaté |
|---|---|
| Socle Laravel, Filament, Livewire, MySQL et CI | Présent |
| Authentification, activation, rôles, permissions et MFA | Implémenté sur develop |
| Journal d'audit cryptographique | Implémenté sur develop |
| Salariés, périodes d'emploi et équipes | Implémenté |
| Clients et chantiers | Implémenté |
| Planning à la demi-journée et affectations | Implémenté |
| Relevés, segments horaires et soumission | Socle implémenté |
| Contrôle, correction, revue des chevauchements et validation | Workflow livré par PR #15 ; recette métier à poursuivre |
| Calculs horaires et compteurs | À développer |
| Absences, indemnités et avances | À développer |
| Clôtures et exports | À développer |
| Notifications et signalements | À développer |
| PWA hors ligne et synchronisation | À développer |
| Sauvegardes, restauration et préparation de production | À finaliser |

Les états détaillés, limites et critères de sortie sont dans [ROADMAP.md](ROADMAP.md) et [docs/STATUS_RECONCILIATION.md](docs/STATUS_RECONCILIATION.md).

## Socle technique

- PHP 8.4
- Laravel 13
- Livewire 4
- Filament 5
- MySQL 8
- Pest, Laravel Pint, Larastan/PHPStan
- Vite Plus et GitHub Actions

Les versions exactes doivent être lues dans `composer.lock`, `package-lock.json`, `.nvmrc` et les workflows de la branche utilisée.

## Prérequis et installation

- PHP 8.4 avec `pdo_mysql`, `intl`, `mbstring`, `gd` et `zip`
- Composer 2
- Node.js conforme à `.nvmrc`
- MySQL 8

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
php artisan migrate
php artisan filament:assets
```

Sous PowerShell, utiliser `Copy-Item .env.example .env` à la place de `cp`.

Créer une base de développement et une base de test distinctes. Ne jamais lancer les tests sur une base de développement ou de production. Renseigner les variables `DB_*` dans `.env` avant les migrations.

Le panneau Filament est disponible sur `/admin`. L'accès dépend exclusivement du
rôle du compte, contrôlé côté serveur (`canAccessPanel()`), et non plus d'une
liste d'adresses. Seuls les rôles **Administrateur** et **Bureau/Direction**
ouvrent le panneau ; les autres rôles utilisent les espaces métier `/planning`
et `/espace`, protégés par les mêmes permissions serveur.

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
# Durée du lien d'activation (minutes) : 24 heures validées.
PLANNING_ACTIVATION_TTL_MINUTES=1440
# Durée maximale d'une exception de permission (mois).
PLANNING_EXCEPTION_MAX_MONTHS=12
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
php artisan planning:emergency-lockdown \
    --actor=direction@example.test \
    --reason="Incident de sécurité : compromission suspectée." \
    --confirm

# Réinitialiser la 2FA d'un compte après perte d'accès
php artisan planning:mfa:reset agent@example.test \
    --actor=direction@example.test --reason="Perte de téléphone" --confirm
```

Dans ces exemples, les adresses `direction@example.test` et `agent@example.test`
sont des valeurs fictives à remplacer par des comptes réels actifs. Le compte
passé à `--actor` doit exister, être actif et disposer de la permission requise :
`accounts.update` pour le verrouillage d'urgence. Le motif est obligatoire
(10 à 500 caractères).

Les exceptions de permission sont accordées et révoquées uniquement en ligne de
commande, avec acteur Bureau/Direction, motif d'au moins 10 caractères et
échéance. Une exception ne peut jamais porter sur la gestion des comptes, de
l'audit, des absences, ni sur la validation, la correction ou la réouverture.

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
composer lint:check
composer types:check
composer ci:check
npm run build
```

La couverture minimale de transition configurée ne remplace pas les tests métier spécifiques. Les résultats de CI doivent être vérifiés sur le commit et la branche concernés ; un run vert sur `develop` ne valide ni `master`, ni `production`, ni un déploiement.

## Branches et livraison

Flux cible :

```text
develop -> Pull Request -> master -> Pull Request -> production
```

- Les changements passent par Pull Request et CI verte.
- La fusion de la PR #15 a intégré le workflow des relevés dans `develop`.
- Cette fusion n'a pas promu le code vers `master` ou `production`.
- La CI n'effectue aucun déploiement.
- Aucun déploiement en production sans autorisation humaine explicite et recette de préproduction.

Les divergences entre branches et la promotion du Lot 2 sont suivies dans Jira, notamment PC1-52. Ne pas présumer que `develop`, `master` et `production` sont alignées.

## Points non clôturés

- Terminer les critères restants des relevés (PC1-31) et la recette du workflow.
- Vérifier le périmètre complet de correction et réouverture (PC1-33).
- Réaliser les calculs et compteurs (Lot 6).
- Réaliser les lots 7 à 10.
- Finaliser les contrôles de sauvegarde, restauration et préproduction (Lot 11).
- Résoudre les réserves de configuration et d'exploitation documentées dans la roadmap.

`DatabaseSeeder` crée un utilisateur de démonstration uniquement dans les
environnements `local` et `testing`. Il est ignoré dans les autres
environnements afin d'éviter la création d'un compte au mot de passe connu en
production. Ne jamais exécuter de seeder de démonstration sur une base réelle.

## Configuration de production

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` avec le domaine HTTPS réel
- `SESSION_DRIVER=database` est recommandé en production : il permet la purge
  physique SQL des sessions en plus de l'invalidation logique.
- Un pilote non SQL (`redis`, `file`, etc.) reste compatible avec la révocation :
  la version de session et le `remember_token` sont renouvelés ; la purge
  physique est alors `not_applicable` et aucune requête n'est adressée à la table
  `sessions`. Le pilote `database` n'est donc pas une condition de sécurité de
  l'invalidation logique.
- `SESSION_CONNECTION=sessions` si les sessions utilisent une connexion dédiée ;
  la purge physique utilise alors cette connexion, sans conditionner
  l'invalidation logique.
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
