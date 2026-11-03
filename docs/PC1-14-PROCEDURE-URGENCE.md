# PC1-14 — Procédure de suppléance Bureau / Direction

**Version de travail — à valider avant intégration définitive**

## 1. Objet

Cette procédure organise la continuité de l'activité Bureau / Direction lorsque le titulaire principal est indisponible. Elle s'appuie sur les comptes nominatifs, les rôles, la MFA et le journal d'audit existants. Elle ne crée aucun mécanisme « break-glass », aucune élévation temporaire de privilèges et aucun droit supplémentaire.

## 2. Désignation

- **Suppléant :** Corentin Doizy — Gérant
- **Compte nominatif :** `c.doizy@c2me.ovh`
- **Rôle :** Bureau / Direction standard
- **Compte de référence pour la revue et les contrôles :** `direction@c2me.ovh`

Le compte suppléant est distinct du compte principal, permanent et doté de ses propres identifiants et de sa propre MFA. La création, l'activation et la configuration MFA ont été déclarées réalisées avec succès le 03/11/2026. Aucune preuve ne doit contenir de mot de passe, secret MFA, code de récupération ou lien d'activation.

## 3. Déclenchement

Corentin Doizy peut déclencher lui-même la suppléance lorsqu'il constate l'indisponibilité du titulaire principal. Aucun accord préalable n'est requis. Le déclenchement doit répondre à un besoin réel de continuité et s'inscrire dans ses responsabilités de Gérant.

## 4. Prise de relais

1. Constater l'indisponibilité et le besoin opérationnel.
2. Se connecter avec `c.doizy@c2me.ovh`.
3. Utiliser sa propre MFA.
4. Vérifier le rôle Bureau / Direction attendu.
5. Réaliser les opérations nécessaires dans le cadre de ses responsabilités.
6. Vérifier que les opérations sensibles sont attribuées à son identité dans le journal d'audit.

Le partage d'identifiants, l'utilisation de la session du titulaire principal et l'enregistrement d'actions sous l'identité d'une autre personne sont interdits.

## 5. Situations particulières

- **Mot de passe perdu :** utiliser la procédure normale de récupération.
- **MFA indisponible :** appliquer la procédure existante de réinitialisation MFA, avec acteur Bureau / Direction habilité, motif, confirmation et journalisation. Aucun contournement n'est autorisé.
- **Compromission suspectée :** évaluer le recours à `planning:emergency-lockdown` conformément à sa procédure. Cette commande révoque globalement les sessions et n'accorde aucun droit. Elle exige `--actor`, `--reason` et `--confirm`; tous les utilisateurs devront se réauthentifier, MFA comprise.

## 6. Retour à la normale

Lorsque le titulaire principal redevient disponible :
1. Vérifier que son compte est actif et que son authentification fonctionne.
2. Confirmer la reprise normale de ses responsabilités.
3. Examiner les opérations réalisées pendant la suppléance à partir du journal d'audit.
4. Identifier et traiter les anomalies.
5. Consigner la fin de la prise de relais lorsqu'un compte rendu est requis.

Le compte suppléant reste permanent. Sa désactivation est à envisager en cas de départ, de changement de fonction ou de retrait de la désignation, selon la procédure de gestion des comptes.

## 7. Revue après incident

Après chaque incident ayant nécessité une prise de relais exceptionnelle, une revue est réalisée par une personne physique habilitée, désignée via le compte de référence `direction@c2me.ovh`.

Le compte rendu comprend au minimum :
- circonstances et motif du déclenchement ;
- date et heure de début et de fin ;
- identité du titulaire indisponible et du suppléant ;
- opérations effectuées et références des événements d'audit ;
- anomalies ou suspicions de sécurité ;
- mesures correctives, responsables et échéances ;
- confirmation du retour à la normale ;
- identité réelle du réviseur et date de revue.

Le compte de référence désigne le compte utilisé par le responsable ; il ne remplace pas l'identification de la personne physique qui réalise la revue.

## 8. Contrôles périodiques

Un contrôle est réalisé tous les trois mois et après toute évolution importante de l'authentification, des rôles ou des permissions. Le responsable est la personne physique habilitée utilisant le compte de référence `direction@c2me.ovh`, avec la participation de Corentin Doizy.

Vérifier :
- état actif du compte suppléant ;
- connexion et fonctionnement MFA ;
- rôle Bureau / Direction standard et permissions attendues ;
- accès aux fonctionnalités nécessaires à la continuité ;
- attribution correcte des actions de test dans le journal d'audit ;
- maintien des accès du titulaire principal ;
- absence de partage d'identifiants ou de contournement des rôles.

Chaque contrôle donne lieu à une fiche datée mentionnant contrôleur, participant, résultats, anomalies, actions correctives et conclusion. Les tests doivent être non destructifs : ne pas lancer un verrouillage global de production ni effectuer d'opérations métier réelles uniquement pour tester la suppléance.

## 9. Conservation des preuves

Conserver la désignation, les fiches de contrôles trimestriels, les comptes rendus de revue et les références d'audit utiles selon les règles documentaires de l'entreprise. Ne jamais conserver de secrets d'authentification dans ces documents.

## 10. Statut

| Élément | Valeur |
|---|---|
| Ticket | PC1-14 |
| Suppléant | Corentin Doizy — Gérant |
| Compte suppléant | `c.doizy@c2me.ovh` |
| Compte de référence revue/contrôle | `direction@c2me.ovh` |
| Fréquence | Trimestrielle |
| Date des décisions | 03/11/2026 |
| Statut | Projet documentaire à relire et valider |

Cette procédure ne vaut pas clôture de PC1-14. La clôture nécessite l'intégration documentaire, la validation formelle et la vérification des preuves et critères de clôture.
