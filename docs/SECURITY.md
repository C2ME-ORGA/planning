# Planning C2ME — Exigences de sécurité et confidentialité

## Contrôle d’accès (implémenté au lot 2)
- Modèle de rôles fonctionnels : Administrateur, Bureau/Direction, Conducteur de travaux, Chef d’équipe, Ouvrier. Un seul rôle par compte.
- L’Administrateur est un rôle technique : il ne dispose d’aucun droit métier par défaut (uniquement la lecture du journal d’audit). Il ne peut ni créer de compte, ni s’attribuer le rôle Bureau/Direction, ni valider ses propres opérations.
- Appliquer policies/gates sur toutes les opérations côté serveur, jamais uniquement dans l’interface.
- 2FA TOTP obligatoire (avec codes de récupération) pour tous les comptes du panneau Filament, donc pour Administrateur et Bureau/Direction. Le panneau est fermé aux autres rôles par `canAccessPanel()`.
- Création de compte par Bureau/Direction uniquement ; pas d’inscription libre. L’Administrateur technique ne peut pas créer de compte.
- Premier compte Bureau/Direction : amorçage exclusivement en CLI (`planning:bootstrap-bureau`), refusé si un compte Bureau/Direction existe déjà ou si l’adresse est déjà utilisée, avec lien d’activation à usage unique et audit à acteur système. Aucun contournement de la 2FA n’est possible.
- Activation et réinitialisation via liens sécurisés à usage unique et durée limitée. La durée d’activation est configurable (valeur par défaut à valider) ; la réinitialisation de mot de passe reste à 60 minutes.
- Session expirant après 15 minutes d’inactivité ; cookies sécurisés en production.
- Désactivation avec révocation immédiate des sessions/tokens via la base de sessions ; un filtre serveur déconnecte en plus tout compte désactivé avant même l’authentification Filament. Historique conservé, aucun compte supprimé.
- L’e-mail du compte est unique globalement (choix plus strict que « unique parmi les comptes actifs ») ; changements audités.
- Auto-validation : réservée au Bureau/Direction, avec motif obligatoire et événement d’audit dédié. Un conducteur/chef ne peut pas approuver sa propre proposition.
- Exceptions de permission : ciblées, motivées (≥ 10 caractères), révocables, limitées à 12 mois, interdites à soi-même et à l’Administrateur. Elles ne peuvent couvrir que `planning.propose`, `timesheet.control` et `availability.view-perimeter` ; jamais les comptes, l’audit, les absences, la validation, la correction ou la réouverture. Elles ne contournent pas les règles de sécurité S02/S03.
- Urgence : `planning:emergency-lockdown` révoque toutes les sessions sans accorder de droit (pas de break-glass). Une perte de 2FA se traite par `planning:mfa:reset` avec acteur Bureau/Direction, motif et confirmation.

## Données et audit
- Minimiser les données personnelles et la visibilité des données d’absence.
- Les responsables opérationnels voient la disponibilité, pas le détail médical.
- Aucune donnée médicale ni justificatif dans le MVP.
- Audit des changements sensibles : acteur, horodatage, objet, action, anciens/nouveaux états et motif lorsque requis.
- Le journal `audit_events` est append-only : séquence strictement croissante, chaînage HMAC-SHA256 avec une clé d’audit dédiée (`PLANNING_AUDIT_KEY`), distincte d’`APP_KEY` et d’au moins 32 caractères, triggers MySQL interdisant `UPDATE`/`DELETE`, commande `planning:audit:verify`.
- Chaque événement porte l’identifiant de sa clé (`key_id`). Les événements historiques signés avec `APP_KEY` (identifiant nul) restent vérifiables, et les clés d’audit antérieures peuvent être déclarées dans `PLANNING_AUDIT_PREVIOUS_KEYS` après rotation.
- Les clés sensibles (mots de passe, jetons, secrets, données médicales, motifs d’absence) sont expurgées avant écriture ; l’audit ne doit pas exposer indirectement une absence ou un contenu médical. Les lots ultérieurs ne doivent jamais placer de texte médical ou de motif d’absence détaillé dans le champ libre `reason` : l’expurgation porte sur les clés, pas sur le contenu libre.
- L’Administrateur ne peut que lire les événements d’audit, jamais les modifier.
- **Limite documentée :** une personne disposant à la fois de la clé d’audit et d’un accès écriture direct à la base pourrait recalculer la chaîne. La protection repose donc aussi sur la séparation des accès, les sauvegardes et la surveillance. Ce point devra être couvert par l’exploitation (lot 11). La rotation de la clé d’audit est prise en charge via `PLANNING_AUDIT_PREVIOUS_KEYS` ; en revanche, la rotation d’`APP_KEY` rend les événements historiques signés avec l’ancienne clé applicative non vérifiables.
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
