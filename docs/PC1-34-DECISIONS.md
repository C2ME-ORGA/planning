# PC1-34 — Calculs horaires et agrégats mensuels

Ce document décrit les règles implémentées par les services de calcul et les
limites volontairement laissées aux tickets PC1-35 et PC1-36. Il ne vaut ni
validation de paie ni autorisation de déploiement.

## Périmètre livré

- Calcul du travail effectif par catégorie, avec fusion des chevauchements.
- Calcul hebdomadaire des heures supplémentaires au-delà de 39 h.
- Agrégats mensuels par mois civil et par catégorie.
- Proratisation de la référence mensuelle selon les jours calendaires de
  présence.
- Résolution d'une référence individuelle par date d'effet, via un contrat de
  fournisseur.

## Règles appliquées

### Sources

Seuls les relevés actuellement au statut `Validée` alimentent les calculs
officiels. Les statuts `Brouillon`, `À contrôler`, `À corriger` et `Prête pour
validation` sont exclus. Le filtrage est effectué en SQL par
`ValidatedTimesheetSelector`. Une réouverture exclut immédiatement le relevé.

Le planning prévisionnel n'est jamais lu par les services de calcul.

### Travail effectif et chevauchements

Seule la catégorie `TravailEffectif` alimente les heures supplémentaires et le
compteur mensuel. Les catégories `Trajet`, `Pause`, `Formation` et `Autre` sont
suivies séparément et ne sont jamais fusionnées avec le travail effectif.

Les intervalles de travail effectif sont triés puis fusionnés avant calcul. Une
minute commune n'est comptée qu'une fois. Les chevauchements réels restent
identifiables dans le diagnostic, avec les identifiants des segments. Les
intervalles seulement adjacents peuvent être fusionnés sans anomalie.

### Semaine et heures supplémentaires

La semaine va du lundi 00:00 au dimanche 23:59, heure locale `Europe/Paris`. Le
seuil est de 39 h, soit 2 340 minutes. Le déficit est informatif et n'est jamais
reporté. Aucune majoration salariale n'est calculée.

Les changements d'heure sont absorbés par le calcul en minutes écoulées. Une
semaine à cheval sur deux mois est calculée en entier pour les heures
supplémentaires, puis ventilée par date civile pour les agrégats mensuels.

### Agrégats mensuels

Les mois sont des mois civils. Les segments sont découpés sur les dates locales
réelles, y compris lorsqu'ils traversent minuit ou un changement de mois. Chaque
catégorie est fusionnée séparément.

Le résultat expose un écart informatif, un crédit potentiel et un déficit. Il ne
crée aucun solde, aucun mouvement de compteur et aucune retenue.

### Référence mensuelle

La référence par défaut est de 169 h, soit 10 140 minutes. Une référence
individuelle peut la remplacer. Une modification prend effet au premier jour du
mois suivant et n'est jamais rétroactive. Pour un même mois d'effet, le dernier
changement fourni par le provider remplace le précédent.

En cas d'entrée ou de sortie en cours de mois :

```text
référence proratisée = référence × jours calendaires de présence / jours du mois
```

Les jours de présence sont inclusifs. Les absences, congés et jours fériés ne
réduisent pas la référence dans PC1-34.

L'arrondi de la référence proratisée est effectué une seule fois, au quart
d'heure le plus proche, avec un seuil de bascule à 7 min 30 s. Une référence
couvrant le mois entier n'est pas arrondie. Les durées réellement travaillées ne
sont jamais arrondies.

Si aucune période d'emploi ne couvre le mois, aucune référence ni aucun compteur
n'est produit.

## Services

| Service | Rôle |
|---|---|
| `App\Support\TimeInterval` | Intervalle UTC semi-ouvert `[start, end)` |
| `App\Services\Hours\IntervalMerger` | Tri, fusion et diagnostic des chevauchements |
| `App\Services\Hours\CategoryIntervals` | Filtrage par catégorie et ventilation journalière |
| `App\Services\Hours\WeeklyHoursCalculator` | Seuil hebdomadaire de 39 h |
| `App\Services\Hours\EmploymentPresence` | Jours calendaires de présence |
| `App\Services\Hours\MonthlyReferenceChange` | Référence individuelle et date d'effet |
| `App\Services\Hours\MonthlyReferenceProvider` | Contrat de fourniture des références |
| `App\Services\Hours\NullMonthlyReferenceProvider` | Fournisseur sans référence individuelle |
| `App\Services\Hours\MonthlyReferenceResolver` | Résolution de la référence applicable |
| `App\Services\Hours\ProratedReference` | Proratisation et arrondi au quart d'heure |
| `App\Services\Hours\MonthlyHoursAggregator` | Agrégats mensuels par catégorie |
| `App\Services\Hours\ValidatedTimesheetSelector` | Sélection SQL des relevés validés |
| `App\Services\Hours\OfficialHoursCalculator` | Composition des calculs officiels |

Les calculs sont purs : ils ne créent ni segment, ni audit, ni mouvement de
compteur.

## Donnée manquante et limite

Il n'existe pas encore de table de références mensuelles individuelles
historisées. PC1-34 ne crée donc aucun stockage persistant : la référence
individuelle est fournie par un `MonthlyReferenceProvider`, et le fournisseur par
défaut ne retourne aucun changement. La référence standard de 169 h s'applique
alors à tous les salariés.

Un ticket ultérieur devra, après validation du schéma :

- créer un stockage historique des références ;
- enregistrer chaque changement avec acteur, motif, valeur avant/après et date
  d'effet ;
- remplacer le fournisseur nul par une implémentation adossée à ce stockage ;
- conserver l'absence de recalcul rétroactif.

## Hors périmètre

Les mouvements persistants du compteur, la consommation d'un solde, les
ajustements, les paiements, les clôtures et les instantanés historiques relèvent
de PC1-35 et PC1-36.
