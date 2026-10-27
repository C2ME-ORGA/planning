# PC1-28 — Décisions métier et règles implémentées

Les questions ouvertes du lot PC1-28 ont été arbitrées. Ce document décrit les
règles définitives appliquées par le code et les tests. Il ne vaut ni
autorisation de déploiement ni validation de paie.

## 1. Statuts et cycle de vie des chantiers

### Référentiel officiel

Les seuls statuts autorisés sont ceux de `App\Enums\SiteStatus` :

| Valeur stockée | Libellé affiché |
| --- | --- |
| `a_preparer` | À préparer |
| `planifie` | Planifié |
| `en_cours` | En cours |
| `suspendu` | Suspendu |
| `en_reception` | En réception |
| `termine` | Terminé |
| `annule` | Annulé |

La colonne `sites.status` reste une chaîne courte pour préserver l'historique.
Toute nouvelle écriture utilise une valeur de l'enum. Les libellés historiques
connus (`en préparation`, `en cours`, `réception`, `terminé`) sont normalisés
par migration ; les valeurs inconnues sont conservées telles quelles et aucune
transition n'est acceptée tant qu'elles subsistent.

Le statut initial est imposé à `a_preparer`. Le formulaire ne permet pas de le
choisir librement : les changements passent par les actions de la liste et par
`SiteService`.

### Matrice des transitions ordinaires

| Statut actuel | Destinations autorisées |
| --- | --- |
| À préparer | Planifié, Annulé |
| Planifié | En cours, Suspendu, Annulé |
| En cours | Suspendu, En réception, Annulé |
| Suspendu | Planifié, En cours, Annulé |
| En réception | En cours, Terminé |
| Terminé | Aucune transition ordinaire — réouverture uniquement |
| Annulé | Aucune transition ordinaire — réouverture uniquement |

Les transitions sont contrôlées dans `SiteService::transition()`, jamais
uniquement dans l'interface. Chaque transition valide produit l'événement
d'audit `site.status_changed` avec l'ancien statut, le nouveau statut, l'auteur
et la date.

### Annulation d'un chantier en cours

Le passage `En cours` → `Annulé` exige un motif non vide (500 caractères
maximum). Le motif est enregistré dans le champ `reason` de l'événement
d'audit. Sans motif, la transition est refusée côté serveur.

### Réouverture exceptionnelle

`Terminé` et `Annulé` sont terminaux par défaut. La réouverture est une action
distincte, protégée par la permission dédiée `sites.reopen` et par
`sites.manage` :

| Statut terminal | Statuts opérationnels cibles |
| --- | --- |
| Terminé | En réception, En cours, Planifié |
| Annulé | À préparer, Planifié, En cours |

La réouverture exige un motif non vide. Elle produit l'événement d'audit
`site.reopened` avec l'auteur, la date, l'ancien statut, le nouveau statut et le
motif, dans la même transaction que la mise à jour du chantier.

La permission `sites.reopen` n'est pas accordée par `sites.manage` : dans le
référentiel actuel, seul le rôle `bureau_direction` (Bureau / Direction) la
possède. Aucun rôle « Direction » séparé n'existe.

### Archivage des chantiers

- L'archivage est indépendant du statut : il ne modifie jamais `status`.
- Un chantier archivé reste visible et consultable selon `sites.view`.
- Sa modification, son changement de statut et sa réouverture sont refusés par
  la policy et par le service.
- L'archivage conserve la ligne, la relation client et l'historique d'audit
  (`site.archived`).
- Aucune suppression physique n'est exposée.

## 2. Archivage et restauration des clients

### Archivage conditionnel

L'archivage d'un client est refusé si au moins un de ses chantiers possède un
statut autre que `termine` ou `annule`. Le contrôle inclut les chantiers déjà
archivés. Le message d'erreur indique le nombre de chantiers bloquants et, dans
la limite de cinq, leurs noms.

L'archivage du client est indépendant de celui de ses chantiers : aucun
`archived_at` de chantier n'est modifié.

### Visibilité et modification

- Les clients archivés restent dans la liste principale, avec un badge
  « Archivé » et la date d'archivage.
- Ils restent consultables via la page de consultation et selon `clients.view`.
- Leur modification est refusée par la policy et par `ClientService::update()`.
- L'archivage produit l'événement `client.archived`.

### Restauration

La permission dédiée `clients.restore` est attribuée exclusivement au rôle
`bureau_direction`. La restauration :

- est une action explicite disponible sur un client archivé ;
- est contrôlée côté serveur par la permission et la policy ;
- ne restaure que le client, jamais ses chantiers ;
- produit l'événement `client.restored` avec l'auteur et la date.

**Écart documenté :** le référentiel ne contient qu'un rôle combiné
`bureau_direction` (« Bureau / Direction ») ; il n'existe pas de rôle `direction`
séparé ni de rôle `bureau` distinct. La revue demande que la restauration soit
réservée à « Direction » et refusée au « Bureau ». Cette séparation ne peut pas
être garantie avec le référentiel actuel. L'arbitrage retenu pour ce lot est de
conserver `clients.restore` sur le rôle combiné, sans inventer de rôle ni de
drapeau de compte. Un test de matrice explicite documente ce comportement. Une
séparation ultérieure des rôles devra faire l'objet d'une décision et d'une
migration dédiées.

### Suppression définitive

La suppression physique d'un client est interdite dans toutes les voies prises
en charge :

- les policies `delete`, `deleteAny`, `forceDelete` et `forceDeleteAny`
  renvoient `false` ;
- aucune action Filament de suppression n'est déclarée ;
- l'événement Eloquent `deleting` lève une exception ;
- la clé étrangère des chantiers est en `restrictOnDelete`.

L'archivage est le seul mécanisme de retrait du portefeuille courant.

## 3. Données et validation des clients

### Champs

| Champ | Règle |
| --- | --- |
| `company_name` | Obligatoire, 190 caractères maximum |
| `contact_name` | Facultatif, distinct de la raison sociale, 190 caractères maximum |
| `email` | Obligatoire, syntaxe valide, 254 caractères maximum |
| `phone` | Facultatif, formats français et internationaux courants |
| `street` | Obligatoire (rue et numéro), 255 caractères maximum |
| `address_complement` | Facultatif, 255 caractères maximum |
| `postal_code` | Obligatoire, 20 caractères maximum |
| `city` | Obligatoire, 120 caractères maximum |
| `country` | Obligatoire, prérempli `France` à la création, modifiable |

L'e-mail est stocké en minuscules après suppression des espaces périphériques.
Le téléphone est validé par `App\Rules\ValidPhoneNumber` : préfixes `+` ou `00`,
séparateurs usuels acceptés, entre 6 et 20 chiffres. Aucun format français
exclusif n'est imposé.

Le champ historique `address` est conservé et rendu nullable. Aucune découpe
heuristique des adresses existantes n'est effectuée : `Client::fullAddress()`
privilégie les champs structurés lorsqu'ils existent, sinon l'ancienne valeur.

### Doublons potentiels

- La comparaison porte sur la raison sociale et l'e-mail, après `trim` et
  passage en minuscules.
- Aucune contrainte d'unicité en base n'est ajoutée.
- L'enregistrement n'est jamais bloqué par un doublon.
- Après création ou modification, une notification informative signale les
  correspondances.
- Lors d'une modification, le client courant est exclu.
- La détection s'exécute côté serveur et exige `clients.view` ; seules les
  raisons sociales des correspondances sont affichées.
- Les tests couvrent séparément un doublon par raison sociale et un doublon par
  e-mail, y compris avec des espaces et une casse différents.

### Performance de la recherche de doublons

Le moteur cible est MySQL 8. La requête utilise `LOWER(TRIM(...))` sur
`company_name` et `email`. Aucun index ordinaire ne peut servir cette expression :
la recherche effectue donc un balayage de la table `clients`. Le portefeuille
client actuel est de faible volume et la détection n'intervient qu'après une
création ou une modification, ce qui ne justifie pas d'optimisation prématurée.

Aucun index fonctionnel, colonne générée ou colonne normalisée n'est ajouté dans
ce lot. Si le volume augmente, la solution à évaluer est une colonne normalisée
maintenue à l'écriture (ou une colonne générée MySQL) avec un index, en
conservant la comparaison insensible à la casse et aux espaces. Cette limite est
également signalée dans le code de `ClientService::findDuplicates()`.

## 4. Permissions et audit

Permissions ajoutées au référentiel :

- `clients.restore` — restaurer un client archivé ;
- `sites.reopen` — réouvrir un chantier terminé ou annulé.

Les deux sont attribuées uniquement au rôle `bureau_direction` et ne sont pas
éligibles aux exceptions temporaires.

Actions d'audit ajoutées :

- `client.archived`, `client.restored` ;
- `site.status_changed`, `site.reopened`.

## 5. Migrations et compatibilité

La migration initiale `2026_10_27_000001_create_clients_table.php` n'est pas
modifiée : elle crée le schéma historique minimal (`company_name`, `address`,
`phone`, `email`, timestamps). Les colonnes d'adresse structurée et les champs
métier sont ajoutés une seule fois par
`2026_10_27_000003_add_client_business_fields.php`. Il n'y a donc pas de colonne
dupliquée ni de double ajout.

La migration `000003` rend `address` nullable pour les nouveaux clients
structurés, sans réécrire les valeurs historiques. La migration `000004`
normalise uniquement les libellés de statut connus et laisse les valeurs
inconnues intactes.

Deux tests d'intégration MySQL isolés vérifient :

- le schéma final sur une base neuve, l'unicité des colonnes, la nullabilité de
  `address` et l'index `archived_at` ;
- une mise à niveau depuis le schéma initial : les données historiques du client
  et du chantier sont conservées, les nouveaux champs restent nuls et le statut
  historique `en cours` est normalisé en `en_cours`.

Ces tests utilisent une base temporaire créée et supprimée pour l'exécution,
sur le même moteur MySQL que la suite de tests.

## 6. Fichiers de référence

- Référentiel et matrice : `app/Enums/SiteStatus.php`.
- Règles clients : `app/Services/Clients/ClientService.php`.
- Règles chantiers : `app/Services/Sites/SiteService.php`.
- Autorisations : `app/Policies/ClientPolicy.php`, `app/Policies/SitePolicy.php`.
- Interface : `app/Filament/Resources/Clients`, `app/Filament/Resources/Sites`.
- Migrations : `2026_10_27_000003_add_client_business_fields.php`,
  `2026_10_27_000004_normalize_site_statuses.php`.
- Tests : `tests/Feature/ClientsAndSites`.

## 7. Limites connues

- L'archivage des chantiers n'est pas réversible depuis l'interface ; aucune
  décision de restauration de chantier n'a été prise.
- Les adresses historiques restent dans leur champ libre jusqu'à une saisie
  manuelle des champs structurés ; aucune reprise automatique n'est faite.
- La détection de doublons est informative et limitée à dix correspondances ;
  elle effectue un balayage complet en l'absence d'index normalisé.
- Le rôle `bureau_direction` reste combiné : la restriction de restauration au
  seul « Direction » n'est pas applicable sans séparation des rôles.
- Aucun calcul de paie, majoration, virement ou retenue n'est introduit.

Aucun déploiement n'est effectué par ce lot.
