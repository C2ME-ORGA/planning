# PC1-29 — Planning à la demi-journée et affectations d'équipe

Ce document décrit l'implémentation du planning chantier par demi-journée. Il
repose sur les critères PC1-29 fournis et sur les spécifications existantes
(`docs/SPECIFICATIONS.md`, section 4). Il ne remplace pas PC1-30, qui traitera
l'historique, les conflits avancés et l'interface mobile.

## 1. Modèle retenu

### `planning_assignments`

Une ligne représente l'affectation d'une équipe à un chantier pour une date
civile et une demi-journée :

- `site_id` : chantier, clé étrangère `restrictOnDelete` ;
- `team_id` : équipe, clé étrangère `restrictOnDelete` ;
- `work_date` : date civile `Y-m-d`, sans heure ni fuseau ;
- `half_day` : `morning` ou `afternoon`, contrôlé par contrainte SQL ;
- timestamps.

Aucune composition d'équipe n'est recopiée dans cette table. Les membres sont
déduits à la date planifiée depuis `team_assignments`.

### `planning_assignment_exclusions`

Une exclusion retire un salarié des membres planifiés d'une affectation :

- `planning_assignment_id`, `employee_id` ;
- contrainte unique sur le couple ;
- une exclusion ne crée ni absence, ni temps réel, ni affectation d'équipe.

### Demi-journée

`App\Enums\PlanningHalfDay` fixe deux valeurs (`morning`, `afternoon`) et leurs
libellés. La date est stockée séparément et interprétée comme date civile
métier. Le matin et l'après-midi d'une même date sont deux créneaux distincts :
ils ne déclenchent pas de chevauchement entre eux.

## 2. Composition de l'équipe

`App\Services\Planning\PlanningRoster` retourne les salariés dont une affectation
d'équipe non annulée couvre la date planifiée, que cette affectation soit
principale ou temporaire, et dont une période d'emploi couvre également cette
date.

- Une affectation temporaire reste un enregistrement distinct : le planning ne
  crée, ne déplace et ne modifie aucune ligne `team_assignments`.
- L'équipe principale historisée du salarié reste donc inchangée.
- Les exclusions sont validées contre cette composition : un salarié qui n'est
  pas membre de l'équipe à cette date ne peut pas être exclu.
- Les exclusions existantes restent visibles dans le formulaire de modification,
  même si le salarié a quitté l'équipe entre-temps ; le service refuse alors
  l'enregistrement tant que l'exclusion n'est pas corrigée.

## 3. Chevauchements

Deux familles de chevauchements sont détectées sur la même date et la même
demi-journée :

1. **Équipe** : la même équipe est déjà affectée à un autre chantier.
2. **Salarié** : un membre non exclu de la nouvelle affectation est déjà planifié
   sur un autre chantier, sauf s'il est exclu de l'autre affectation.

Règles appliquées :

- l'enregistrement n'est pas bloqué ;
- aucune affectation existante n'est déplacée, remplacée ou supprimée ;
- l'avertissement est affiché à l'utilisateur ;
- les avertissements sont enregistrés dans le contexte de l'événement d'audit
  `planning.assignment_created` ou `planning.assignment_updated`, sous la clé
  `overlaps` ;
- une action « Vérifier les chevauchements » permet de recalculer et d'afficher
  les conflits d'une affectation existante.

Les membres des équipes concernées par un créneau sont chargés en une seule
requête groupée (`PlanningRoster::membersByTeam`) : le nombre de requêtes de
détection ne dépend pas du nombre d'affectations concurrentes. Le filtrage par
date, par affectation principale ou temporaire et par période d'emploi reste
identique à celui de la composition unitaire.

Un doublon strict (même chantier, même équipe, même date, même demi-journée) est
refusé : ce n'est pas un conflit de planification mais une ligne redondante. Le
service renvoie un message explicite et une contrainte unique en base protège
contre une course concurrente.

## 4. Validation et sécurité

- `planning.manage` est nécessaire pour créer ou modifier une affectation.
- `planning.propose` permet la consultation, sans écriture.
- L'Administrateur technique et l'Ouvrier n'ont aucun accès au planning.
- Le service revérifie aussi la consultation du chantier et de l'équipe via
  leurs policies : un identifiant soumis ne suffit pas.
- Un chantier archivé ne peut pas recevoir d'affectation.
- Les exclusions sont limitées aux membres réels de l'équipe à la date.
- Les identifiants de chantier et d'équipe inexistants sont refusés par le
  formulaire et par la page avant l'appel au service.
- L'écriture de l'affectation, des exclusions et de l'audit est transactionnelle.
  Un échec d'audit annule l'ensemble.
- La suppression physique est refusée par la policy et par l'événement Eloquent
  `deleting` ; l'historique doit être conservé pour PC1-30.

## 5. Audit

Actions ajoutées :

- `planning.assignment_created` ;
- `planning.assignment_updated`.

L'audit contient le chantier, l'équipe, la date, la demi-journée, les exclusions
et les avertissements de chevauchement. Les avertissements audités ne
contiennent que les identifiants utiles (affectation, chantier, équipe,
salarié), jamais les noms de salariés ni les messages destinés à l'interface.
L'auteur et l'horodatage sont fournis par le journal d'audit existant.

## 6. Interface

Le panneau Filament expose une ressource « Planning » :

- liste des affectations avec date, demi-journée, chantier, équipe et nombre
  d'exclusions ;
- filtres par chantier, équipe et demi-journée ;
- création et modification ;
- sélection multiple des salariés exclus, alimentée par la composition de
  l'équipe à la date ;
- action de vérification des chevauchements ;
- aucune action de suppression.

L'interface mobile et la vue calendrier ne sont pas traitées dans PC1-29.

## 7. Hypothèses et points non arbitrés

- **Chantiers terminés ou annulés** : seuls les chantiers archivés sont bloqués.
  Aucune règle n'a été inventée pour les statuts non archivés.
- **Jours non ouvrés** : aucun filtre week-end, jour férié ou fermeture n'est
  appliqué, faute de règle explicite.
- **Absences** : le module d'absence n'existe pas encore. Une exclusion n'est pas
  convertie en absence.
- **Confirmation humaine** : les chevauchements sont des avertissements
  persistants ; aucun workflow de confirmation ou de blocage n'est introduit.
  Aucun conflit n'est classé bloquant dans ce lot. Le doublon strict est refusé
  pour intégrité, pas comme conflit de planning. Si le métier souhaite bloquer
  certains chevauchements, l'arbitrage relève de PC1-30.
- **Historique/versionnement** : aucune annulation ni version d'affectation
  n'est implémentée. PC1-30 devra définir la conservation et les corrections.
- **Concurrence** : les doublons stricts sont protégés par une contrainte
  unique. Les avertissements de chevauchement, eux, ne sont pas sérialisés par
  un verrou ; deux créations concurrentes sur des chantiers différents peuvent
  toutes deux être acceptées avec un avertissement calculé avant l'écriture de
  l'autre.

## 8. Tests

Les tests PC1-29 couvrent :

- création d'une affectation complète ;
- validation des dates, chantiers archivés, exclusions non membres et doublons
  stricts ;
- exclusions valides, dédupliquées et sans modification de l'équipe principale ;
- composition limitée aux salariés couverts par une période d'emploi ;
- préservation d'une affectation temporaire et de l'équipe principale ;
- chevauchement d'équipe et de salarié, avertissement non bloquant et
  conservation de l'affectation existante ;
- absence de chevauchement entre matin et après-midi et entre dates adjacentes ;
- absence de chevauchement après exclusion du salarié concerné ;
- modification auditée ;
- autorisations positives et négatives, y compris les accès Filament ;
- intégrité transactionnelle en cas d'échec d'audit ;
- refus de suppression physique.

Commandes et résultats réels : voir `docs/TESTING.md`, section PC1-29.
