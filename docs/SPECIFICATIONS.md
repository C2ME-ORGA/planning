# Planning C2ME — Spécifications fonctionnelles

**Statut :** document de référence fonctionnel pour le MVP. Les points juridiques et techniques signalés comme à confirmer ne sont pas considérés comme validés.

## 1. Périmètre et principes
Application Laravel indépendante pour SARL C2ME, dédiée au planning, aux relevés d’heures et au suivi opérationnel RH. Dépôt, base et déploiement propres. Pas d’intégration BATISTACK dans le MVP.

## 2. Rôles et accès
Rôles : Administrateur, Bureau/Direction, Conducteur de travaux, Chef d’équipe, Ouvrier. Toutes les autorisations sont vérifiées côté serveur. L’administrateur technique ne reçoit pas automatiquement l’accès aux données métier. Les comptes sont créés par le bureau uniquement ; pas d’inscription libre. Les comptes administrateur et bureau/direction exigent la double authentification.

## 3. Salariés, emploi et équipes
Une fiche salarié peut contenir plusieurs périodes d’emploi. Les sorties et retours sont historisés. Un retour crée une nouvelle période d’emploi et ne transfère pas automatiquement un ancien solde compteur. Les équipes et affectations temporaires sont historisées ; une affectation temporaire ne modifie pas l’équipe principale. Les changements d’équipe ne transfèrent pas automatiquement les accès historiques.

## 4. Chantiers et planning
Seul Bureau/Direction modifie le planning. Granularité d’une demi-journée. L’affectation d’une équipe inclut ses membres par défaut, avec possibilité d’exclure un salarié pour une affectation donnée. Les chevauchements déclenchent un avertissement, ne bloquent pas l’enregistrement et sont journalisés. Le planning n’est jamais utilisé pour préremplir les heures réelles. Les chantiers terminés sont archivés.

## 5. Relevés d’heures
Catégories : Travail effectif, Trajet, Pause, Formation, Autre activité professionnelle. Saisie à la minute, sans arrondi ni déduction automatique de pause repas. Les segments peuvent traverser minuit et les totaux journaliers sont répartis sans double comptage. Un segment supérieur à 12 heures génère un avertissement. Les chevauchements sont signalés ; un segment incomplet bloque la soumission.

Lorsqu’une journée attendue ne contient aucune heure, le salarié indique un motif : oubli de saisie, absence enregistrée, jour non travaillé selon le planning, ou autre (commentaire obligatoire). Les absences sont des événements distincts, saisis par Bureau/Direction. Pas de pièces jointes dans le MVP.

## 6. Workflow de relevé
États : Brouillon, À contrôler, À corriger, Prête pour validation, Validée. La transmission n’est confirmée qu’après réponse du serveur et doit être idempotente. Les corrections par salarié/chef d’équipe dépendent des droits et de l’état. Le contrôle du chef d’équipe n’est pas la validation finale. Seul Bureau/Direction valide définitivement. Une réouverture d’un relevé validé exige un motif. Chaque transition et correction est auditée. Contrôle groupé possible, avec traces séparées et exclusion des anomalies bloquantes.

## 7. Calculs horaires et compteurs
Heures supplémentaires : volume de travail effectif au-delà de 39 heures par semaine, du lundi 00:00 au dimanche 23:59 heure locale française. Trajet, pause, formation et autre activité professionnelle sont exclus. Le calcul ne détermine aucune majoration de salaire. Un déficit sous 39 heures est informatif ; aucun report automatique entre semaines.

Compteur mensuel : travail effectif uniquement, contre une référence de 169 heures par défaut, configurable individuellement. Les autres catégories sont suivies séparément. Le compteur mensuel est indépendant du calcul hebdomadaire des heures supplémentaires. Une différence positive peut créditer ; une différence négative consomme uniquement un solde positif disponible. Le déficit restant déclenche une alerte au bureau ; aucun solde négatif et aucune retenue automatique. Les ajustements de référence requièrent un motif, une confirmation et une trace avant/après ; aucun recalcul historique silencieux. Les périodes trimestrielles suivent les trimestres civils.

Le journal du compteur enregistre crédits, utilisations, paiements et ajustements, avec solde avant/après, auteur, date et commentaire. Le paiement d’heures exige salarié, volume, trimestre, date de paiement, commentaire facultatif et auteur/horodatage. Pas de montant en euros ni d’ordre de paiement.

## 8. Absences, indemnités et avances
Les absences sont saisies par Bureau/Direction. Les responsables opérationnels voient la disponibilité, sans détails médicaux. Les propositions de paniers repas ne constituent pas automatiquement une décision d’éligibilité : le bureau confirme, modifie ou supprime. Le taux et montant appliqués sont conservés ; les changements de taux ne recalculent pas les périodes validées. Les grands déplacements sont saisis manuellement par le bureau ou un responsable habilité, jamais déduits automatiquement du planning. Les autres indemnités sont saisissables.

Les avances sont saisies manuellement : salarié, date, montant, mois de paie facultatif, commentaire, statut prévu/en attente ou payé. Les doublons potentiels sont signalés, mais une avance distincte peut être confirmée. Corrections et annulations sont auditées. Pas de virement bancaire ni de calcul automatique de solde de paie.

## 9. Clôtures et exports
La clôture mensuelle est manuelle, une fois les relevés attendus contrôlés. Les données clôturées sont gelées pour les modifications ordinaires ; une réouverture exige un motif. Exports provisoires avant clôture et définitifs après clôture. Exports individuels par salarié, classeur Excel global avec synthèse et feuilles détaillées, PDF global imprimable et synthèses individuelles. Les versions sont conservées avec période, date, numéro de version et lien vers la version remplacée. Pas de comparaison automatique provisoire/définitif. Le résumé global des compteurs reste dans l’application.

## 10. Comptes et sécurité
Activation via lien unique à durée limitée ; le salarié crée son mot de passe et vérifie son adresse e-mail. L’e-mail de connexion/notification est unique parmi les comptes actifs. Les changements de contact sont audités. Réinitialisation par lien sécurisé ; l’administrateur ne connaît jamais les mots de passe. Déconnexion après 15 minutes d’inactivité. La désactivation révoque immédiatement l’accès, sans effacer l’historique.

Conditions d’utilisation et information de confidentialité distinctes, versionnées et datées, acceptées à l’activation et à nouveau si une modification importante l’exige. Pas de stockage de documents RH dans le MVP. Les durées légales de conservation restent à confirmer avant production.

## 11. PWA et mode hors ligne
Le hors ligne permet de consulter le dernier planning synchronisé et les relevés précédemment chargés, créer/modifier des relevés modifiables et conserver des brouillons locaux. Pas de changement de planning, gestion d’absence, validation finale, clôture ou export RH hors ligne.

Synchronisation à la reconnexion, à la réouverture de l’application, après échec temporaire et sur action manuelle. États : Synchronisé, En attente, Erreur, Conflit. Le statut synchronisé n’est affiché qu’après confirmation serveur. Une clé idempotente empêche les doublons. En conflit, les deux versions sont conservées, sans écrasement silencieux. Le salarié résout uniquement les conflits sur brouillon non transmis ; les relevés transmis sont résolus par un rôle autorisé ; les périodes validées/clôturées exigent réouverture par le bureau. Les données locales doivent être chiffrées et isolées par compte. La déconnexion ne doit ni exposer les données du compte précédent ni perdre silencieusement des données non synchronisées. La conception du stockage et des clés est à valider avant implémentation complète.

## 12. Notifications
Préférences pour notifications non essentielles ; les alertes obligatoires de workflow ne peuvent pas être désactivées. Notifications dans l’application et e-mail pour événements importants. Rappel le lendemain matin si la saisie de la veille est incomplète, sauf absence enregistrée ou jour non travaillé. Rappel hebdomadaire au chef d’équipe le lundi matin pour la semaine précédente. Pas d’e-mail au salarié lors d’un retour en correction et pas de notification automatique au chef d’équipe à chaque soumission : suivi par tableaux de bord.

## 13. Signalements
Tout utilisateur peut créer un signalement : bug/erreur, synchronisation, affichage, ergonomie, autre. Contexte technique minimal : page, date/heure, version de l’application, navigateur/appareil, état de synchronisation, identifiant d’incident ; jamais de mot de passe, jeton ou contenu complet de relevé automatiquement. Priorités : Bloquant, Important, Mineur. L’auteur propose une priorité ; un rôle habilité peut la modifier. Le numéro est attribué après confirmation serveur. L’auteur voit ses signalements ; Bureau/Direction et administrateurs habilités voient l’ensemble. Questions/réponses, statuts, priorités et historique sont audités. L’auteur peut confirmer la résolution ou signaler que le problème persiste ; fermeture sans réponse possible par une personne habilitée. Filtres par statut, type, priorité, date, auteur et section. Pas de suppression ; annulation avec motif. Les doublons sont liés au signalement principal sans supprimer leur propre ID/historique. Notification dans l’application lorsque des précisions sont demandées ou qu’un signalement est déclaré doublon. Afficher dates de création/mise à jour, sans délai de traitement promis.

## 14. Sauvegardes et qualité
Sauvegarde chiffrée toutes les six heures, séparée de la production. Rétention : 7 jours de sauvegardes quotidiennes et 4 sauvegardes hebdomadaires supplémentaires. Contrôles d’intégrité et tests périodiques de restauration. Les détails de rotation, RPO/RTO et calendrier de restauration restent à préciser.

Tests Pest, Pint et GitHub Actions. Couverture globale progressive : 70 % puis 80 %, sans remplacer les tests spécifiques des fonctionnalités critiques. La CI bloque les PR en cas d’échec d’un contrôle obligatoire. Environnements distincts développement/préproduction/production. Déploiement production manuel après validation préproduction ; fusion seule ne déploie pas.

## 15. Points à confirmer avant production
- Validation du traitement des heures et indemnités par le gestionnaire de paie.
- Durées légales de conservation et règles de confidentialité.
- Conception et gestion des clés de chiffrement local.
- Stratégie précise de résolution des conflits hors ligne.
- RPO/RTO, rotation détaillée et fréquence des tests de restauration.
- Hébergement, domaine, supervision et compatibilité navigateur/appareil.
- Transitions exactes de workflow et paramètres finaux.
