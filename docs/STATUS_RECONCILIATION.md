# Rapprochement code, documentation et Jira

**Référence :** 1er novembre 2026 — dépôt `C2ME-ORGA/planning`, branche `develop`, commit `41bdd8f87d7577e7d2f2b6bfa4525b5f519e4c94`.
**PR #15 :** https://github.com/C2ME-ORGA/planning/pull/15

## Méthode et limites

Comparaison de l'arborescence GitHub, des routes, migrations, modèles, services, policies, interfaces, tests, documents de référence et des 50 tickets Jira PC1. Ce document n'est ni une revue ligne par ligne de tous les fichiers, ni une recette utilisateur exhaustive, ni une vérification de déploiement. Les résultats de tests ne sont repris que lorsqu'ils ont été rapportés ou documentés.

## Livraison PR #15

Fusionnée dans `develop` le 01/11/2026. Merge commit `41bdd8f87d7577e7d2f2b6bfa4525b5f519e4c94`, source `1e9e588685fb4751f0ccc5ba70c98f0643e2bfba`. CI #49 verte sur le commit source. Contrôles ciblés rapportés : 65 tests / 479 assertions, Pint OK, PHPStan 0 erreur, `git diff --check` propre. Aucun déploiement.

## État du code sur develop

**Présent :** socle Laravel/Filament/Livewire/MySQL/CI ; authentification, activation, rôles, permissions, MFA et révocation ; journal HMAC-SHA256 ; salariés, périodes d'emploi et équipes ; clients, chantiers et planning ; relevés, segments, soumission, workspace salarié/équipe/Bureau, contrôle, revue des chevauchements, validation individuelle/groupée, historique et journal.

**Non livré ou non démontré :** calculs et compteurs ; absences, indemnités et avances ; clôtures et exports versionnés ; notifications et signalements ; PWA et synchronisation hors ligne ; sauvegardes/restauration et préparation complète de production.

## Rapprochement Jira

| Ticket | Statut Jira observé | Constat / recommandation |
|---|---|---|
| PC1-4 | À faire | Epic de cadrage ; confirmer s'il doit être clos ou conservé comme référence. |
| PC1-5 | À faire | Authentification/permissions présentes sur develop ; En cours recommandé jusqu'à recette, réserves et promotion. |
| PC1-6 | À faire | Audit cryptographique présent ; En cours recommandé jusqu'à clôture des réserves et promotion. |
| PC1-7 | Terminé | Cohérent avec PC1-15 à PC1-22 terminés. |
| PC1-8 | À faire | Clients/chantiers/planning présents ; En cours recommandé jusqu'à recette mobile et correction du rattachement PC1-53. |
| PC1-9 | À faire | Lot workflow partiellement livré ; En cours recommandé. |
| PC1-10 | À faire | Calculs/compteurs absents ; cohérent. |
| PC1-11 | À faire | Matrice des rôles présente ; descriptif à actualiser et critères à confirmer. |
| PC1-12 | À faire | Activation, cycle de compte et révocation présents ; descriptif à actualiser, recette à confirmer. |
| PC1-13 | À faire | Règles de validation et de correction confirmées : auto-validation Bureau/Direction motivée, séparation des tâches et transmission directe après correction effective implémentées ; recette à confirmer. |
| PC1-14 | À faire | Procédures/commandes d'urgence documentées ; vérifier la recette et actualiser le descriptif. |
| PC1-15 à PC1-22 | Terminé | Cohérent avec le périmètre RH livré. |
| PC1-23 à PC1-27 | À faire | Lots 7 à 11 non livrés ; cohérent. |
| PC1-28 à PC1-30 | Terminé | Cohérent avec clients/chantiers/planning présents. |
| PC1-31 | En cours | Cohérent : saisie et workspace présents, critères résiduels/recette à vérifier ; motif sans saisie différé au Lot 7. |
| PC1-32 | Terminé | Cohérent avec PR #15 fusionnée dans develop. |
| PC1-33 | En cours | Parcours de correction/réouverture livrés : correction Bureau avec preuve d'une modification effective dans le cycle courant, interface de correction des segments, transmission directe motivée, indépendance du validateur et historique multi-cycles. Prêt pour recette. |
| PC1-34 à PC1-49 | À faire | Fonctionnalités non livrées ; cohérent. |
| PC1-50 | À faire | Audit documentaire largement réalisé ; En cours recommandé jusqu'à validation ADR/licence et validation humaine. |
| PC1-51 | À faire | Socle/CI présents ; En cours recommandé pour réserves de configuration, alignement et promotion. |
| PC1-52 | À faire | Promotion vers master non réalisée ; statut cohérent. |
| PC1-53 | Terminé | Jeu de démonstration RH terminé, mais parent Jira actuel PC1-8 (Lot 4) est incohérent avec le contenu Lot 3 (PC1-7). Corriger le parent après confirmation. |

## Corrections documentaires réalisées dans cette branche

- README : suppression de l'affirmation obsolète indiquant que chantiers, planning et relevés ne sont pas implémentés.
- ROADMAP : état réécrit après fusion de PR #15, avec distinction entre develop, master et production.
- Présent document : inventaire fonctionnel, rapprochement ticket par ticket et réserves.

## Réserves à conserver

1. Visibilité détaillée des chevauchements pour les chefs d'équipe : arbitrage métier restant.
2. Redis/Predis : client choisi, mais stores Laravel à définir et configuration d'exemple à aligner.
3. Alignement de `.nvmrc`, README et CI à vérifier depuis les fichiers courants.
4. Robustesse concurrente du bootstrap du premier compte Bureau/Direction.
5. Atomicité des opérations sensibles de compte et de leur audit.
6. Ordre/pagination de vérification de la chaîne d'audit dans les cas d'import/restauration.
7. Transport réel des notifications d'activation : les tests avec faux transport ne prouvent pas la livraison.
8. ADR 001–151 introuvables ; licence non décidée.
9. PC1-53 rattaché à PC1-8 au lieu de PC1-7 ; hiérarchie à confirmer.
10. Recette mobile du Lot 4 non établie par la seule présence des tests.

## Statuts recommandés

- PC1-5, PC1-6 : En cours.
- PC1-8 : En cours.
- PC1-9 : En cours.
- PC1-31 : En cours.
- PC1-32 : Terminé.
- PC1-33 : prêt pour recette (En cours jusqu'à validation humaine).
- PC1-50, PC1-51 : En cours.
- PC1-52 : À faire.

Ne pas clôturer un ticket uniquement parce qu'un modèle, un écran ou un service existe : tous ses critères applicables doivent être vérifiés.
