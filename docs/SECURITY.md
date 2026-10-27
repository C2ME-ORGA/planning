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

## Salariés et équipes (lot 3)
- La fiche salarié est indépendante du compte applicatif. Un compte ne peut être lié qu'à un seul salarié ; la fiche et son historique survivent à la désactivation du compte.
- Les permissions `employees.view`, `employees.manage`, `teams.view` et `teams.manage` sont réservées au Bureau/Direction. L'Administrateur technique n'obtient aucun droit RH. Chaque service métier revérifie la permission de l'acteur, y compris pour un appel direct hors interface.
- Une affectation ou une responsabilité doit être entièrement couverte par une période d'emploi. Les chevauchements de périodes, d'affectations principales et de responsabilités principales sont refusés, y compris en cas d'écritures concurrentes grâce à des verrous MySQL ciblés et une revalidation transactionnelle.
- Un salarié ne peut être responsable principal que d'une seule équipe à la fois. Les responsabilités sur des périodes disjointes ou adjacentes restent autorisées.
- Un responsable principal doit avoir un compte actif et le rôle `chef_equipe` ou `conducteur_travaux` pendant sa responsabilité. Le retrait du rôle, la désactivation du compte ou la déliaison du compte sont refusés tant qu'une responsabilité non terminée subsiste ; une réaffectation ou une clôture explicite est requise.
- Une sortie est refusée tant que des affectations ou responsabilités resteraient actives après la date de sortie. Aucune affectation n'est tronquée automatiquement. Une affectation future peut être annulée avec motif ; une affectation commencée se clôt avec une date de fin. L'historique est conservé dans les deux cas.
- La sortie peut désactiver le compte applicatif lié uniquement sur option explicite et confirmée. Cette désactivation est immédiate, révoque les sessions et reste dans la même transaction que la sortie ; sans option, le compte est conservé. Elle n'est acceptée que pour une sortie effective aujourd'hui ou dans le passé. Elle est également refusée si une responsabilité d'équipe couvre encore la date effective de désactivation (dates inclusives : end_date nulle ou >= aujourd'hui, y compris une responsabilité se terminant à la date de sortie) ; clôturez ou réaffectez d'abord la responsabilité, ou désactivez après sa fin.
- Aucune donnée médicale, aucun document RH et aucun justificatif ne sont stockés dans ce lot.
- Les périodes d'emploi sont strictement append-only : le modèle refuse toute mise à jour Eloquent directe et toute suppression, sans méthode d'autorisation temporaire. La clôture n'est possible qu'à un point unique autorisé — `EmployeeLifecycleService::exit()`, sous permission, verrou, relecture et transaction — via une écriture ciblée dont le compte de lignes affectées est vérifié (0 ligne = exception et rollback). Une écriture SQL directe reste possible hors application et reste une limite documentée. Aucune procédure de correction rétroactive n'est définie à ce stade.
- Les verrous nommés utilisent `PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS` (5 secondes par défaut) ; en cas d'attente dépassée, l'écriture est refusée plutôt que validée sans contrôle. L'ordre est équipe puis salariés triés par identifiant.

## Clients et chantiers (PC1-28)
- `clients.view`, `clients.manage`, `sites.view` et `sites.manage` sont réservées au Bureau/Direction. La restauration d'un client exige `clients.restore` et la réouverture d'un chantier exige `sites.reopen`, permissions distinctes accordées au seul rôle `bureau_direction` et non éligibles aux exceptions temporaires.
- Les transitions de statut sont imposées par `SiteService` selon la matrice `SiteStatus`, y compris pour un appel direct hors interface. Aucun statut libre n'est accepté. L'annulation depuis `En cours` et toute réouverture exigent un motif non vide.
- L'archivage d'un client est refusé tant qu'un chantier associé n'est ni terminé ni annulé, y compris si ce chantier est archivé. L'archivage et la restauration d'un client ne modifient jamais l'archivage des chantiers.
- Les clients et chantiers archivés restent consultables selon les permissions existantes mais ne peuvent plus être modifiés. La restauration d'un client ne restaure pas ses chantiers.
- La suppression physique est refusée par les policies, aucune action Filament de suppression n'est exposée, l'événement Eloquent `deleting` lève une exception et la clé étrangère des chantiers est en `restrictOnDelete`.
- L'e-mail client est obligatoire et validé côté serveur. Le téléphone est facultatif et validé par une règle tolérante aux formats français et internationaux courants.
- La détection de doublons est informative et non bloquante. Elle s'exécute côté serveur, après `trim` et insensibilité à la casse, exclut le client courant et exige `clients.view` ; seules les raisons sociales des correspondances sont affichées. Aucune contrainte d'unicité n'est ajoutée en base.
- Les adresses historiques ne sont pas découpées automatiquement ; les champs structurés sont validés pour toute nouvelle saisie.

## Données et audit
- Minimiser les données personnelles et la visibilité des données d’absence.
- Les responsables opérationnels voient la disponibilité, pas le détail médical.
- Aucune donnée médicale ni justificatif dans le MVP.
- Audit des changements sensibles : acteur, horodatage, objet, action, anciens/nouveaux états et motif lorsque requis. Les opérations salariés, périodes, équipes, affectations, responsabilités, sorties et réaffectations utilisent le journal existant.
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
