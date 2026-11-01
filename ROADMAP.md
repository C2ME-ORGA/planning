# Planning C2ME — Roadmap de référence

**Référence du code :** branche `develop`, commit de fusion PR #15 `41bdd8f87d7577e7d2f2b6bfa4525b5f519e4c94` (1er novembre 2026).  
**Projet Jira :** PC1 — [tableau des tickets](https://sarlc2me.atlassian.net/issues/?jql=project%20%3D%20PC1%20ORDER%20BY%20key%20ASC).  
**Principe :** un statut « Terminé » signifie que les critères du ticket sont satisfaits et vérifiés ; du code présent sur `develop` ne signifie ni promotion vers `master`, ni déploiement.

## Synthèse de l'état actuel

- Le socle applicatif est en place.
- Les lots RH (Lot 3), clients/chantiers/planning (Lot 4) et le workflow de relevés (incrément majeur du Lot 5) sont présents sur `develop`.
- La PR #15 est fusionnée dans `develop`. Aucun déploiement n'est inclus.
- Les lots calculs/compteurs, absences/indemnités, clôtures/exports, notifications/signalements, PWA/synchronisation et sauvegardes/production restent à réaliser.
- Jira comporte des tickets dont les statuts ou descriptions sont en retard sur le code ; voir [le rapport de rapprochement](docs/STATUS_RECONCILIATION.md).

## Règles de pilotage

1. Distinguer systématiquement « implémenté sur develop », « vérifié par tests », « accepté fonctionnellement », « promu sur master » et « déployé ».
2. Ne clôturer un ticket qu'après vérification de tous ses critères d'acceptation applicables.
3. Conserver les décisions métier confirmées ; les propositions non confirmées restent identifiées comme telles.
4. Toute évolution de code doit inclure ses tests et la mise à jour des documents concernés.
5. Vérifier les versions exactes dans les fichiers de verrouillage et les workflows, pas dans une ancienne description.
6. Pas de push direct, merge ou déploiement hors du processus de Pull Request et de l'autorisation explicite requise.
7. Ne pas inventer les ADR 001–151 absentes du dépôt.

## État par lot

### Lot 0 — Audit du dépôt et documentation
**État : réalisé en grande partie ; clôture documentaire/humaine à confirmer.**  
Le dépôt contient les documents de référence, l'inventaire des composants applicatifs et les consignes de développement. Les ADR 001–151 ne sont pas présentes et la licence reste à décider. La présente réconciliation actualise README, roadmap et Jira ; le ticket PC1-50 reste ouvert jusqu'à validation des réserves documentaires.

### Lot 1 — Socle, configuration et CI
**État : socle présent ; vérifications d'exploitation et alignement à finaliser.**  
Laravel, Filament, Livewire, MySQL, tests et workflow CI sont présents. La CI verte de develop est une preuve limitée au commit concerné. Revoir la cohérence Node/.nvmrc/CI, les variables d'environnement, Redis/Predis et la divergence des branches avant promotion. Jira : PC1-51.

### Lot 2 — Authentification, rôles, permissions et audit
**État : implémenté sur develop ; promotion et recette globale non réputées terminées.**  
Présents : rôles et permissions serveur, contrôle d'accès au panneau, MFA TOTP, activation, désactivation/révocation, exceptions bornées, journal append-only HMAC-SHA256, clé dédiée et vérification/rotation. La PR #4 a intégré le lot dans develop. Les tickets PC1-5 et PC1-6 restent en cours tant que la promotion contrôlée, les réserves techniques et l'acceptation métier ne sont pas closes. PC1-52 suit la revue et la promotion vers master ; aucune promotion n'est présumée.

Réserves documentées : configuration effective Redis/Predis et stores concernés ; alignement Node ; robustesse concurrente du bootstrap du premier Bureau ; atomicité de certaines opérations de compte et audit ; pagination/vérification de la chaîne d'audit ; vérification du transport réel des notifications d'activation.

### Lot 3 — Salariés, périodes d'emploi et équipes
**État : implémenté et tickets de lot déclarés terminés.**  
Présents : fiches salariés, liaison facultative aux comptes, périodes d'emploi, sorties/réembauches, équipes, affectations historisées, responsables principaux, règles de cohérence et interfaces Filament. Les tickets PC1-15 à PC1-22 et PC1-53 sont marqués terminés dans Jira. Vérifier le rattachement Jira de PC1-53 : son contenu concerne le Lot 3, alors que sa relation parent actuelle pointe vers PC1-8 (Lot 4). Ne pas modifier cette hiérarchie sans confirmation.

### Lot 4 — Clients, chantiers et planning
**État : fonctionnalités principales implémentées sur develop ; recette fonctionnelle finale à confirmer.**  
Présents : modèles et gestion clients/chantiers, statuts et archivage, planning à la demi-journée, affectations d'équipe/exclusions, historique, avertissements de conflits et interface mobile. PC1-28, PC1-29 et PC1-30 sont terminés. L'Epic PC1-8 ne doit pas être clôturé uniquement sur la présence du code : vérifier les critères de recette mobile et corriger le rattachement anormal de PC1-53 avant clôture.

### Lot 5 — Relevés et workflow
**État : incrément majeur livré sur develop ; lot non terminé.**

- **PC1-31 — Saisie et segments : En cours.** Modèles, catégories/segments, services de création et soumission, règles temporelles, audit et idempotence applicative sont présents via PR #11. L'interface hebdomadaire et le workspace sont désormais présents via PR #15. La vérification des cas limites et l'affichage non bloquant de l'avertissement de durée sont couverts ; voir [le rapport de finalisation](docs/PC1-31-PC1-33-FINALISATION.md). Conserver le ticket ouvert pour la recette complète et les motifs de journée sans saisie (explicitement différés au Lot 7). Ne pas confondre idempotence de soumission applicative avec idempotence de synchronisation hors ligne.
- **PC1-32 — Workflow : Terminé sur develop.** PR #15 fusionnée le 01/11/2026 : contrôles, revue des chevauchements, validation individuelle/groupée, contrôles d'accès, auto-validation Bureau/Direction avec motif et audit, interfaces et journal. Réserve métier : visibilité détaillée des chevauchements par les chefs d'équipe à arbitrer. Aucun déploiement.
- **PC1-33 — Correction/réouverture : En cours de clôture.** PR #15 apporte des parcours de correction et de reprise dans le workspace. L'audit des critères (correction par acteur autorisé, retour dans le workflow, réouverture motivée, conservation de l'historique avant/après, tests de refus) est documenté dans [le rapport de finalisation](docs/PC1-31-PC1-33-FINALISATION.md). Réserve : le passage direct `Brouillon → Prête pour validation` pour le Bureau/Direction n'est pas implémenté et exige une décision métier. Ne pas clôturer avant la recette et la levée de la contradiction de numérotation Jira.

Les motifs de journée attendue sans heure sont différés au Lot 7 conformément à l'arbitrage enregistré. Les notifications et le mode hors ligne restent hors périmètre du Lot 5.

### Lot 6 — Calculs et compteur
**État : à faire.**  
Développer les calculs hebdomadaires au-delà de 39 h de travail effectif, agrégats mensuels, référence de 169 h configurable, compteur, journal des mouvements, paiements/ajustements et clôture trimestrielle. Conserver l'indépendance entre heures supplémentaires et compteur. Aucun calcul de majoration de paie ni retenue automatique. Jira : PC1-10, PC1-34 à PC1-36.

### Lot 7 — Absences, indemnités et avances
**État : à faire.**  
Développer les absences administratives avec confidentialité, motifs de journée sans saisie, indemnités et taux historisés, grands déplacements saisis manuellement, paniers repas soumis à confirmation du bureau et avances avec statuts/contrôle de doublons. Ne pas déduire automatiquement une absence ou une indemnité du planning. Jira : PC1-23, PC1-37 à PC1-39.

### Lot 8 — Clôtures et exports
**État : à faire.**  
Clôture mensuelle manuelle, gel des données, réouverture motivée, exports PDF/XLSX individuels et globaux, versionnement et reproductibilité. Respecter les droits de périmètre et tracer les exports. Jira : PC1-24, PC1-40 à PC1-42.

### Lot 9 — Notifications et signalements
**État : à faire.**  
Préférences, notifications obligatoires, rappels conditionnels, signalements avec numéro serveur, priorité, échanges, historique, doublons et annulation motivée. Ne jamais joindre automatiquement secrets ou relevés complets. Jira : PC1-25, PC1-43 à PC1-45.

### Lot 10 — PWA hors ligne et synchronisation
**État : à faire.**  
Prototype PWA, stockage local chiffré et isolé par compte, file de synchronisation idempotente, états de synchronisation, reprise, conflits conservant les deux versions et absence d'écrasement silencieux. La validation finale, clôture et exports RH ne sont pas autorisés hors ligne. Jira : PC1-26, PC1-46 à PC1-47.

### Lot 11 — Sécurité, sauvegardes et production
**État : à faire.**  
Sauvegardes chiffrées, séparation de la production, rétention prévue, contrôles d'intégrité, tests de restauration isolée, préproduction, configuration et rollback. Les RPO/RTO et certains détails opérationnels restent à préciser. Jira : PC1-27, PC1-48 à PC1-49.

## Prochain ordre de travail recommandé

1. Terminer le rapprochement et la validation des tickets PC1-31 et PC1-33.
2. Confirmer les réserves de Lot 2 et la stratégie de promotion via PC1-52.
3. Effectuer la recette humaine finale du Lot 4 avant clôture de PC1-8.
4. Démarrer Lot 6 (calculs et compteurs), après validation des exemples métier par le bureau/gestionnaire de paie.
5. Enchaîner Lots 7, 8, 9, 10 et 11 selon les dépendances.

## Critères de sortie communs

Pour chaque ticket : code livré sur la branche cible prévue, tests ciblés et contrôles qualité documentés, permissions vérifiées côté serveur, documentation actualisée, critères métier validés par le responsable, lien vers PR/commit/CI et réserves restantes explicites. Un ticket n'est pas « Terminé » simplement parce qu'un écran ou un modèle existe.
