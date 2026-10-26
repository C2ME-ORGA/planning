# Planning C2ME — Stratégie de tests

## Principes
Tester le comportement observable, les permissions et les invariants métier. Les tests des fonctionnalités critiques sont obligatoires même si le seuil global de couverture est atteint.

## Couverture
Objectif progressif : au moins 70 % puis 80 % de couverture globale. Le seuil ne remplace pas les tests spécifiques des calculs horaires, permissions, workflows, exports/versionnement et synchronisation hors ligne.

Au lot 0, un seuil de transition de 35 % est appliqué par `composer test:coverage` ; il doit être relevé progressivement avec l'ajout des fonctionnalités et de leurs tests.

## Base de données de test
Les tests automatisés utilisent une base MySQL dédiée et jetable, `planning_c2me_test`, configurée dans `phpunit.xml`. La CI démarre un service MySQL isolé. Ne jamais exécuter la suite de tests contre une base de développement ou de production.

## Tests à prévoir
- Permissions : matrice par rôle, accès direct et accès à des données hors périmètre.
- Comptes : activation unique/expirée, réinitialisation, 2FA, expiration et révocation.
- Salariés : périodes d’emploi, sortie/retour, historisation des équipes.
- Planning : demi-journées, exclusions, chevauchements, archives, pas de préremplissage.
- Heures : précision minute, passage minuit, chevauchement, segment incomplet, seuil 12 heures, raison pour journée vide.
- Workflow : transitions admises/refusées, transmission idempotente, correction, validation, réouverture motivée, audit.
- Calculs : seuil hebdomadaire 39 h, frontières semaine/mois, travail effectif seulement, compteur mensuel, ajustements et journal.
- RH : absences, taux de panier conservé, grands déplacements manuels, avances et doublons.
- Clôture/export : gel, réouverture, accès, contenu, versionnement.
- Notifications : destinataires et absence de rappel injustifié.
- Signalements : accès, priorité, numérotation après confirmation, doublons et absence de secrets.
- Hors ligne : retry sans doublons, conservation en échec, conflit, isolement des comptes, logout, stockage saturé.
- Sauvegardes : test de restauration isolée.

## Automatisation
- Exécuter Pest, Pint en mode vérification, analyse statique et build front-end par CI.
- Les vérifications obligatoires doivent échouer la PR en cas d’échec.
- Documenter commandes et résultats réels ; ne jamais simuler un résultat.
- Tester les migrations sur base de test et valider la restauration en environnement isolé.
