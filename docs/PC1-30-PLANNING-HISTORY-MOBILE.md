# PC1-30 — Historique, conflits et consultation mobile

## Historique

PC1-30 réutilise `AuditLogger` et les événements existants
`planning.assignment_created` et `planning.assignment_updated`. Aucun journal
parallèle ni table de versionnement n'est ajouté.

`PlanningAssignmentHistory` expose une projection limitée aux lecteurs du
planning (`planning.manage` ou `planning.propose`) :

- opération, date, auteur et rôle ;
- valeurs pertinentes avant/après ;
- exclusions ajoutées ou retirées ;
- avertissements de chevauchement constatés.

Le contexte technique complet de l'audit reste réservé à `audit.view`. Les noms
sont résolus à la consultation et ne sont pas stockés dans l'audit.

Les modifications et leur audit restent transactionnels. Une affectation liée à
un chantier archivé est consultable mais ne peut pas être modifiée.

## Conflits

`PlanningDayBoard` charge les affectations d'une date, les relations, les
membres d'équipe en lot et l'historique autorisé. Les conflits d'équipe et de
salarié sont affichés par demi-journée, restent non bloquants et ne modifient
jamais une autre affectation.

La correction N+1 de PC1-29 est conservée : les membres sont chargés via
`PlanningRoster::membersByTeam()`.

## Chantiers archivés

Les affectations historiques d'un chantier archivé restent consultables si
l'utilisateur possède un droit de lecture du planning. Les pages d'affectation,
les actions Filament et le service refusent toute écriture sur ces affectations.

## Parcours mobile

La route authentifiée `/planning` affiche une consultation mobile en lecture :

- choix de la date ;
- navigation au jour précédent/suivant et retour à aujourd'hui ;
- sections Matin et Après-midi ;
- chantier, équipe, membres, exclusions et avertissements ;
- accès à l'historique autorisé ;
- indication explicite des chantiers archivés.

Le composant Livewire réautorise l'accès à chaque requête. Aucun formulaire de
création ou modification n'est exposé sur ce parcours et aucune bibliothèque
frontend supplémentaire n'est utilisée.

## Protection des heures réelles

Le planning ne crée ni relevé, ni pointage, ni segment horaire. Les exclusions
restent des exclusions de planning et ne deviennent pas des absences. Les tests
`PlanningNoRealHoursTest` vérifient l'absence de tables ou d'écritures d'heures
réelles après consultation et modification du planning.

## Limites reportées

- Les avertissements de chevauchement restent non bloquants.
- Aucun verrou supplémentaire n'est introduit pour les créations concurrentes.
- L'annulation/versionnement métier complet des affectations reste à définir.
- Aucun relevé d'heures n'est implémenté avant validation humaine de l'epic PC1-8.
