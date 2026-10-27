# PC1-28 — Jeu de démonstration et revue visuelle

Ce document décrit le jeu de données fictif destiné à la revue visuelle des
clients et des chantiers. Il ne vaut ni validation métier ni autorisation de
déploiement.

## 1. Mécanisme retenu

- Commande Artisan : `planning:demo:clients-sites`.
- Seeder dédié : `Database\Seeders\DemoClientsAndSitesSeeder`.
- Le seeder n'est **pas** appelé par `DatabaseSeeder` : `db:seed` en production
  ne crée donc aucune donnée de démonstration.
- Les lignes sont créées directement avec Eloquent, sans passer par
  `ClientService` ni `SiteService`. Aucun événement d'audit métier n'est simulé.
- Les données sont additives, créées dans une transaction et identifiées par le
  suffixe `(démo)` dans la raison sociale et le nom du chantier.
- Une seconde exécution ne crée pas de doublon : chaque client est retrouvé par
  sa raison sociale exacte, chaque chantier par son couple client + nom.

## 2. Volume et contenu

| Élément | Valeur |
| --- | --- |
| Clients fictifs | 16 |
| Chantiers fictifs | 40 |
| Chantiers actifs | 34 |
| Chantiers archivés | 6 |
| Clients sans chantier | 2 |
| Clients avec plusieurs chantiers | 14 |

Le jeu couvre :

- des raisons sociales courtes, longues et proches (recherche et tri) ;
- des adresses très courtes et très longues (troncature d'affichage) ;
- des téléphones et e-mails présents ou absents ;
- des noms de chantiers courts et longs ;
- plusieurs chantiers actifs pour un même client ;
- des chantiers archivés, dont un par client concerné.

Les statuts utilisés sont **des libellés de démonstration, pas une liste
officielle** : `en préparation`, `en cours`, `suspendu`, `réception`,
`terminé`. La liste et les transitions restent à arbitrer
(`docs/PC1-28-DECISIONS.md`, point 1).

Aucune donnée personnelle, commerciale ou réelle n'est utilisée. Les adresses
e-mail utilisent exclusivement le domaine réservé `example.test`.

## 3. Sécurité et refus par défaut

Avant toute écriture, la commande affiche l'environnement, la connexion, le
pilote, l'hôte et la base ciblés. Elle refuse de s'exécuter si :

- `APP_ENV` n'est ni `local` ni `testing` ;
- le pilote n'est ni MySQL/MariaDB ni SQLite ;
- pour MySQL/MariaDB, l'hôte n'est pas `127.0.0.1`, `localhost` ou `::1` ;
- pour MySQL/MariaDB, le nom de la base n'est pas dans la liste autorisée ;
- pour SQLite, le fichier cible n'est pas un chemin absolu explicitement
  autorisé.

### MySQL / MariaDB

La liste par défaut est :

```text
planning_c2me,planning_c2me_test,planning_c2me_demo
```

Elle est définie dans `config/planning.php` et peut être adaptée pour une base
locale explicitement dédiée à la démonstration, sans jamais y ajouter une base
de production :

```dotenv
PLANNING_DEMO_ALLOWED_DATABASES=planning_c2me,planning_c2me_demo
```

Si `DB_URL` est utilisée, l'hôte et le nom de base de l'URL sont contrôlés.

### SQLite

SQLite est accepté uniquement si le chemin déclaré correspond exactement à une
entrée de `planning.demo.allowed_sqlite_paths`. La valeur par défaut ne contient
que le fichier standard de l'application :

```text
database/database.sqlite
```

Pour autoriser un autre fichier local, renseigner son chemin **absolu** :

```dotenv
PLANNING_DEMO_ALLOWED_SQLITE_PATHS=/chemin/absolu/vers/demo.sqlite
```

Plusieurs chemins peuvent être séparés par des virgules. Chaque entrée désigne
un fichier précis : aucun motif (`*.sqlite`, `*.db`), aucun répertoire et aucun
chemin relatif ne sont acceptés.

La commande canonicalise le chemin (fichier existant ou répertoire parent) puis
le compare à la liste. Sont refusés : un chemin vide, relatif, contenant un
segment `..`, un caractère NUL ou une barre finale ; une base `:memory:` ou une
URI `file:` ; un lien symbolique ; un répertoire parent introuvable ou non
résoluble. Le chemin affiché par la commande est le chemin canonique réellement
contrôlé. L'inspection ne crée aucun fichier.

Les entrées de la liste blanche ne sont volontairement pas résolues au niveau de
leur dernier segment : remplacer un fichier autorisé par un lien symbolique vers
une autre cible provoque donc un refus. Les liens physiques (hardlinks) ne sont
pas détectables de façon portable et restent une limite connue.

Pour SQLite, préférer `DB_DATABASE` avec un chemin absolu. Une URL SQLite dont
le chemin résolu est relatif est refusée par la politique ci-dessus.

Le contrôle porte sur la configuration déclarée : un tunnel local volontairement
mis en place vers une base distante n'est pas détectable par la commande.

La commande exige en plus l'option `--confirm`. Sans elle, elle affiche la cible
et s'arrête sans écrire (code de sortie en échec).

Elle ne crée ni compte ni mot de passe, ne modifie aucune permission, ne
désactive pas la 2FA et ne produit aucun événement d'audit. Aucune commande
destructive n'est utilisée (`migrate:fresh`, `db:wipe`, suppression globale).

## 4. Préparation de la base

### Option A — base locale existante

```bash
php artisan migrate
php artisan planning:demo:clients-sites          # affiche la cible, n'écrit pas
php artisan planning:demo:clients-sites --confirm
```

Cette option ajoute les lignes de démonstration à la base de développement
courante. Elles y restent tant qu'elles ne sont pas retirées par un
administrateur local.

Si `php artisan migrate` échoue sur une base préexistante incohérente, ne pas
forcer ni réinitialiser la base : utiliser l'option B ou faire arbitrer la
réparation de la base locale. Le seeder échoue alors sans conserver d'écriture.

### Option B — base locale dédiée (recommandée pour une revue isolée)

```bash
mysql -u root -p -e "CREATE DATABASE planning_c2me_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Configurer ensuite, dans le `.env` local non versionné :

```dotenv
DB_DATABASE=planning_c2me_demo
```

Puis :

```bash
php artisan config:clear
php artisan migrate
php artisan planning:demo:clients-sites          # vérification de la cible
php artisan planning:demo:clients-sites --confirm
```

Ne jamais versionner le `.env`, les identifiants de base ni les secrets.

## 5. Compte de revue

Le jeu de données ne crée aucun utilisateur. Utiliser les mécanismes existants :

```bash
php artisan planning:bootstrap-bureau direction@example.test \
    --name="Direction C2ME (démo)" \
    --confirm
```

Le compte reçoit un lien d'activation à usage unique. En local, avec
`MAIL_MAILER=log`, le lien se trouve dans `storage/logs/laravel.log`. Il ne doit
être ni collé dans un ticket ni journalisé dans un dépôt. Après activation, la
2FA TOTP est obligatoire.

Pour les autres rôles :

- Bureau / Direction : peut créer les comptes depuis `/admin/users` et attribuer
  les rôles existants ; le lien d'activation part par le même canal local.
- Administrateur technique : rôle existant, sans permission métier sur les
  clients et chantiers.
- Conducteur / Chef d'équipe / Ouvrier : ne peuvent pas accéder au panneau
  d'administration ; ce comportement est attendu.

Aucun mot de passe de démonstration n'est stocké dans le dépôt, les logs ou cette
documentation.

## 6. URLs du panneau (vérifiées dans le code et les tests)

Le panneau Filament est déclaré sur `/admin` (`AdminPanelProvider`), avec les
ressources Clients et Chantiers :

- `/admin/clients`
- `/admin/clients/{id}/edit`
- `/admin/sites`
- `/admin/sites/{id}/edit`
- `/admin/users`
- `/admin/audit-events` (lecture seule, Administrateur et Bureau/Direction)

## 7. Checklist de revue visuelle

À réaliser avec un compte Bureau / Direction après exécution du jeu de données.

| # | Vérification | Résultat attendu |
| --- | --- | --- |
| 1 | Ouvrir `/admin/clients` | 16 clients de démonstration, triés par raison sociale, pagination cohérente. |
| 2 | Rechercher `Coopérative` | La longue raison sociale est retrouvée. |
| 3 | Trier par raison sociale | L'ordre alphabétique reste lisible avec les accents. |
| 4 | Vérifier la colonne « Chantiers » | 5 pour Groupe Immobilier Horizon et la Société Coopérative ; 0 pour deux clients. |
| 5 | Ouvrir une fiche client | Adresse, téléphone et e-mail s'affichent ; les champs facultatifs vides ne cassent pas la mise en page. |
| 6 | Vérifier les actions client | « Modifier » visible ; aucune action de suppression. |
| 7 | Ouvrir `/admin/sites` | 40 chantiers, triés par nom, avec client, adresse, statut et date d'archivage. |
| 8 | Filtrer « Archivage » sur « Actifs » | 34 chantiers. |
| 9 | Filtrer « Archivage » sur « Archivés » | 6 chantiers, avec une date affichée. |
| 10 | Rechercher un client depuis la liste des chantiers | Les chantiers du client sont retrouvés. |
| 11 | Ouvrir un chantier actif | Formulaire accessible, client associé préchargé. |
| 12 | Ouvrir l'édition d'un chantier archivé | Accès refusé (403) ; le chantier reste consultable dans la liste. |
| 13 | Vérifier les actions d'un chantier archivé | Ni « Archiver » ni « Modifier ». |
| 14 | Vérifier les actions d'un chantier actif | « Archiver » et « Modifier » visibles pour Bureau / Direction. |
| 15 | Vérifier les libellés longs | Les noms et adresses longs sont tronqués ou passent à la ligne sans casser le tableau. |
| 16 | Se connecter en Administrateur technique | `/admin/clients` et `/admin/sites` refusés ; l'audit reste accessible en lecture seule. |
| 17 | Se connecter avec un rôle opérationnel | Aucun accès au panneau d'administration. |
| 18 | Vérifier l'absence de suppression | Aucune action de suppression physique sur clients ou chantiers. |

Cette checklist décrit des résultats attendus ; elle ne constitue pas une
vérification visuelle déjà réalisée par l'auteur du jeu de données.

## 8. Limites et points ouverts

- Les statuts sont des libellés de démonstration ; aucune transition ni
  signification officielle n'est introduite.
- Aucun compte ni mot de passe de démonstration n'est créé par le seeder.
- Aucun historique d'audit factice n'est créé par le seeder. En revanche, toute
  modification effectuée ensuite depuis l'interface produit un événement
  d'audit réel, ce qui est le comportement attendu.
- Le jeu ne couvre ni le planning, ni les relevés d'heures, ni les salariés.
- L'affichage par défaut des chantiers archivés, l'unicité des clients et la
  suppression/archivage des clients restent à arbitrer
  (`docs/PC1-28-DECISIONS.md`).
- Aucune commande de purge n'est fournie : supprimer des lignes de démonstration
  est une opération d'administrateur local, à effectuer uniquement sur une base
  identifiée comme jetable ou de démonstration.
