# Planning C2ME — Exigences de sécurité et confidentialité

## Contrôle d’accès
- Modèle de rôles fonctionnels : Administrateur, Bureau/Direction, Conducteur de travaux, Chef d’équipe, Ouvrier.
- Appliquer policies/gates sur toutes les opérations côté serveur.
- 2FA obligatoire pour Administrateur et Bureau/Direction.
- Panneau Filament restreint par une liste d’adresses autorisées tant que le modèle de rôles n’est pas implémenté ; ne jamais l’ouvrir à tous les comptes.
- Création de compte par Bureau/Direction uniquement ; pas d’inscription libre.
- Activation et réinitialisation via liens sécurisés à usage unique et durée limitée.
- Session expirant après 15 minutes d’inactivité.
- Désactivation avec révocation immédiate des sessions/tokens, historique conservé.
- L’e-mail du compte est unique parmi les comptes actifs ; changements audités.

## Données et audit
- Minimiser les données personnelles et la visibilité des données d’absence.
- Les responsables opérationnels voient la disponibilité, pas le détail médical.
- Audit des changements sensibles : acteur, horodatage, objet, action, anciens/nouveaux états et motif lorsque requis.
- Ne pas journaliser mots de passe, jetons, clés, données RH complètes ou secrets.
- Pas de pièces jointes/documents RH dans le MVP.
- Ne pas supprimer les historiques métier ; annulation avec motif et audit lorsqu’applicable.

## Hors ligne
- Chiffrer les données locales et isoler l’espace de stockage par compte.
- Valider la gestion et la rotation des clés avant l’usage réel.
- Conserver les données locales en attente jusqu’à confirmation serveur et conformément aux règles définies.
- Ne jamais afficher un état synchronisé avant confirmation serveur.
- Conflit : préserver les deux versions, empêcher l’écrasement silencieux.
- À la déconnexion, les données du compte précédent ne doivent pas être accessibles au compte suivant ; les données non synchronisées ne doivent pas être perdues silencieusement.
- Définir une procédure pour appareil partagé, stockage saturé, logout et récupération.

## Sauvegardes et exploitation
- Sauvegardes chiffrées toutes les six heures, séparées de l’environnement de production.
- Rétention : 7 jours de sauvegardes quotidiennes et 4 sauvegardes hebdomadaires supplémentaires.
- Vérifications d’intégrité et tests périodiques de restauration.
- Définir RPO/RTO, rotation exacte, supervision et procédure d’incident avant production.
- Environnements distincts développement, préproduction et production.
- Déploiement production manuel avec sauvegarde, migration contrôlée, santé et plan de retour arrière.

## Points restant à confirmer
Durées de conservation applicables, information des salariés et base légale, gestion des clés de chiffrement client, RPO/RTO, calendrier de restauration et exigences du prestataire d’hébergement doivent être validés avant production.
