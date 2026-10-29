# Planning C2ME — Plan de développement du MVP

**Statut :** feuille de route proposée à valider avant exécution.  
**Dépôt :** https://github.com/C2ME-ORGA/planning  
**Références :** `AGENTS.md`, `docs/SPECIFICATIONS.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TESTING.md`.

## État actuel constaté
Le dépôt contient une application Laravel 13.38 avec Livewire 4.4, Filament 5.10, Pest, Pint, Larastan et un pipeline GitHub Actions corrigé au titre du lot 0. MySQL est la base cible de tous les environnements, tests inclus. Les protections de `master`, `production` et `develop` sont actives. Le premier run CI distant de `develop` est vert. Les ADR 001–151 restent introuvables et ne doivent pas être reconstituées. Ne pas réinstaller/recréer le framework ni mettre à jour les dépendances majeures au titre du lot 0.

## Règles générales
- Lire les documents de référence et examiner les fichiers existants avant chaque lot.
- Vérifier les versions exactes depuis `composer.json`, `composer.lock` et les workflows.
- Ne pas remplacer une décision validée par une hypothèse.
- En cas de contradiction entre code et spécification, documenter la différence puis demander arbitrage si le changement est significatif.
- Ajouter les tests avec le code, ne pas les reporter à la fin.
- Après chaque lot : exécuter tests et contrôles existants, relire le diff, mettre à jour les documents et fournir un compte rendu.
- Ne pas déployer en production sans validation humaine explicite.

## Lot 0 — Audit du dépôt et intégration documentaire
**Travaux :** dresser l’inventaire des workflows, routes, migrations, modèles, pages, dépendances, scripts de test et configuration. Identifier précisément ce qui existe déjà. Ajouter les documents fonctionnels manquants sous `docs/`, créer `AGENTS.md` à la racine et ajouter ce `ROADMAP.md` sans écraser `docs/ARCHITECTURE.md`. Vérifier la cohérence des liens entre documents. Réconcilier les décisions ADR 001–151 avec les documents existants avant de créer/renuméroter un répertoire ADR.  
**Tests/contrôles :** état Git propre ou état initial relevé ; dépendances installables ; commandes Pint, Larastan et Pest documentées ; secrets absents du diff.  
**Acceptation :** documentation versionnée, arborescence connue, écarts code/spécifications listés.  
**Point d’arrêt humain :** valider l’audit avant toute modification fonctionnelle.

## Lot 1 — Socle, configuration et CI
**Travaux :** seulement corriger les lacunes découvertes au lot 0 ; vérifier `.env.example`, timezone métier, configuration de test, pipeline PR et séparation des environnements. Conserver les choix de dépendances existants sauf justification et validation.  
**Tests :** installation depuis un checkout propre, suite Pest, Pint en mode vérification, analyse statique, build front-end et CI.  
**Acceptation :** commandes reproductibles et CI bloquante fonctionnelle.  
**Point d’arrêt humain :** valider le socle avant les fonctionnalités métier.

## Lot 2 — Authentification, rôles et permissions
**Statut :** validation fonctionnelle acquise le 26/10/2026 ; clôture définitive en attente de confirmation humaine. Le point bloquant de la clé d’audit dédiée est traité : `PLANNING_AUDIT_KEY` distincte d’`APP_KEY`, identifiant de clé par événement, vérification des événements historiques et rotation documentée. Tests, Pint et Larastan passent localement ; aucune mise en production n'a été effectuée.
**Travaux :** rôles Administrateur, Bureau/Direction, Conducteur de travaux, Chef d'équipe, Ouvrier ; policies/gates serveur ; 2FA obligatoire pour Administrateur et Bureau/Direction ; activation par lien sécurisé, réinitialisation, session de 15 minutes, désactivation immédiate, journal d'audit.
**Tests :** accès autorisés/refusés par rôle, accès direct interdit, expiration et usage unique des liens, 2FA, révocation de session et absence de secrets dans les logs.
**Acceptation :** matrice d'accès testée.
**Point d'arrêt humain :** valider les permissions avant les modules métier.

## Lot 3 — Salariés, périodes d’emploi et équipes
**Statut :** implémenté le 26/10/2026 puis corrigé après audit indépendant et revue technique complémentaire, en attente de validation humaine. 118 tests, 635 assertions, Pint et Larastan verts. Aucune mise en production n’a été effectuée.
**Travaux :** fiche salarié, périodes d’emploi multiples, équipes principales, responsables, affectations temporaires datées, sorties/retours et historique.  
**Tests :** unicité des valeurs requises, blocage des affectations après sortie, conservation d’historique, retour sans transfert automatique des compteurs/accès.  
**Acceptation :** cycle de vie salarié traçable.  
**Point d’arrêt humain :** valider les cas de sortie et de retour.

## Lot 4 — Clients, chantiers et planning
**Travaux :** gestion des clients/chantiers, archive, planning à la demi-journée, affectation de l’équipe avec exclusions individuelles, avertissements de chevauchement, historique et notifications importantes.  
**Tests :** droits Bureau/Direction, affectations temporaires sans changement d’équipe principale, conflit averti et journalisé, chantier archivé consultable, absence de préremplissage des heures réelles.  
**Acceptation :** scénario de planning complet testé sur mobile.  
**Point d’arrêt humain :** valider avant les relevés.

## Lot 5 — Relevés et workflow
**Statut :** incréments 1 (socle technique des relevés), 2A (autorisations, transitions et traçabilité) et 2B (création d'un relevé, saisie des segments et transmission idempotente par état) livrés et fusionnés via la PR #11. Incrément 2C (contrôle, reprise de correction, préparation à la validation, validation finale et réouverture motivée) implémenté localement sur la branche `pc1-32-workflow-releves`, en attente de validation humaine avant commit, revue et fusion. Restent hors périmètre : interface salarié/Bureau, contrôle groupé, tableaux de bord et idempotence réseau. Le motif obligatoire d'une journée attendue sans heure est différé au Lot 7. La validation humaine de l'incrément 2C reste à effectuer ; PC1-32 n'est pas clôturé.
**Travaux :** segments horaires et cinq catégories, saisie à la minute, passage minuit, motifs d’absence de saisie, états du workflow, soumission idempotente, correction, contrôle groupé, validation finale, réouverture motivée et tableaux de bord.  
**Tests :** durées, segments incomplets, chevauchements, seuil 12 h, transitions autorisées/interdites, idempotence, verrouillage après confirmation serveur, audit, retours en correction et réouverture.  
**Acceptation :** parcours de l’ouvrier au bureau testé de bout en bout.  
**Point d’arrêt humain :** valider tous les scénarios de workflow.

## Lot 6 — Calculs et compteur
**Travaux :** services de calcul testables ; heures effectives au-delà de 39 h/semaine ; agrégats par mois civil ; référence mensuelle 169 h par défaut et réglages individualisés ; journal de mouvements ; crédits, utilisations, paiements et ajustements ; clôture trimestrielle.  
**Tests :** scénarios chiffrés aux seuils, semaine inter-mois, passage minuit, travail effectif uniquement, indépendance du compteur et des heures supplémentaires, solde créditeur/déficit, ajustement avec audit, absence de paie ou retenue automatique.  
**Acceptation :** résultats reproductibles et validés sur exemples par le bureau/gestionnaire de paie.  
**Point d’arrêt humain :** aucune utilisation en production avant revue des règles de paie.

## Lot 7 — Absences, indemnités et avances
**Travaux :** absences administratives, proposition de paniers repas à confirmer, taux historisés, saisie manuelle des grands déplacements/indemnités, avances avec statut et avertissements de doublons.  
**Tests :** droits sur les données d’absence, pas d’éligibilité automatique, taux appliqué conservé, pas de déduction du planning, corrections/annulations auditées.  
**Acceptation :** saisies traçables sans automatisation de paie.  
**Point d’arrêt humain :** validation des règles et taux par le gestionnaire de paie.

## Lot 8 — Clôtures et exports
**Travaux :** clôture mensuelle, gel, réouverture motivée, exports individuels, Excel global, PDF global, versionnement provisoire/définitif.  
**Tests :** accès, cohérence avec données validées, versions conservées, permissions de téléchargement, reproduction d’export.  
**Acceptation :** formats vérifiés par le bureau.  
**Point d’arrêt humain :** approbation des modèles avant usage.

## Lot 9 — Notifications et signalements
**Travaux :** préférences, rappels utiles, notifications obligatoires, signalements avec contexte technique limité, priorités, numéro serveur, échanges, historique, annulation et doublons.  
**Tests :** destinataires, absence de faux rappels, restrictions d’accès, numéro attribué après confirmation serveur, aucun secret ou relevé complet joint automatiquement.  
**Acceptation :** signalements traçables et privés.

## Lot 10 — PWA hors ligne et synchronisation
**Travaux :** PWA mobile ; consultation des données synchronisées ; brouillons locaux ; chiffrement/isolement par compte ; file de sync idempotente ; états Synchronisé/En attente/Erreur/Conflit ; reprises automatiques/manuelles ; conflits sans écrasement silencieux. Commencer un prototype technique dès les fondations, mais n’activer le mode hors ligne réel qu’après validation de la conception de stockage et clés.  
**Tests :** mode avion, reconnexion, retries sans doublons, conservation après échec, conflits, isolation de compte, données non synchronisées à la déconnexion, stockage saturé/indisponible.  
**Acceptation :** aucune perte ni confirmation de sync trompeuse.  
**Point d’arrêt humain :** valider la conception de sécurité locale avant données réelles.

## Lot 11 — Sécurité, sauvegardes et production
**Travaux :** sauvegarde chiffrée toutes les six heures, séparation de la production, 7 jours de sauvegardes quotidiennes et 4 sauvegardes hebdomadaires supplémentaires, restauration, contrôles de santé, vérifications de configuration, procédure de déploiement et rollback.  
**Tests :** restauration en environnement isolé, pipeline complet, permissions et validation de préproduction.  
**Acceptation :** validation de préproduction, sauvegarde/restauration et points juridiques requis.  
**Point d’arrêt humain :** aucune mise en production avant clôture des sujets juridiques et techniques ouverts.

## Dépendances
Les lots 0 à 9 sont réalisés progressivement. Le prototype de stockage hors ligne commence tôt en parallèle ; son intégration complète dépend du workflow des relevés. La production dépend de la réussite des lots 10 et 11.

## Format de compte rendu attendu après chaque lot
1. Résumé des changements et fichiers touchés.
2. Décisions prises et écarts éventuels à la spécification.
3. Tests/commandes exécutés avec résultats exacts.
4. Risques ou tâches restantes.
5. Demande de validation humaine au jalon indiqué.
