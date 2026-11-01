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

## Accès et sécurité

Le panneau Filament est réservé aux comptes actifs Administrateur et Bureau/Direction et exige la double authentification TOTP. Les autres rôles utilisent les espaces métier prévus par leurs permissions.

Le premier compte Bureau/Direction est amorcé par la commande dédiée `planning:bootstrap-bureau`. Il n'existe pas d'inscription libre. La clé du journal d'audit `PLANNING_AUDIT_KEY` doit être distincte d'`APP_KEY`, suffisamment longue et stockée dans le gestionnaire de secrets.

Consulter [docs/SECURITY.md](docs/SECURITY.md) et [docs/OPERATIONS.md](docs/OPERATIONS.md) avant toute opération sensible.

## Tests et qualité

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

La licence du projet reste à décider. Les ADR 001 à 151 n'ont pas été retrouvées ; elles ne doivent pas être inventées ou reconstituées sans source originale.
