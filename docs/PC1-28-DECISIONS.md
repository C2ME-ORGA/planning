# PC1-28 — Décisions et points métier à confirmer

## Périmètre implémenté

- Client : raison sociale, adresse, téléphone et e-mail.
- Chantier : nom, adresse, client associé et statut.
- L'archivage utilise une date d'archivage et conserve la ligne, le client associé et l'historique d'audit.
- Les modifications de chantier archivé sont bloquées par le service métier.
- Les permissions sont vérifiées côté serveur et les opérations de création, modification et archivage sont journalisées.
- La modification d'un client est accessible depuis la liste aux comptes disposant de `clients.manage`, via la policy et le service métier.

## Hypothèses techniques explicites

- La raison sociale, l'adresse client, le nom du chantier, l'adresse chantier, le client associé et le statut sont requis par les formulaires et services.
- Téléphone et e-mail client sont facultatifs.
- Le statut est conservé comme texte court. Aucune liste de statuts, transition ou signification n'est inventée à ce stade.
- Les chantiers ne sont pas supprimables physiquement. La suppression d'un client est également interdite par la policy ; la règle de suppression/archivage des clients n'est pas encore définie fonctionnellement.

## Comportement retenu pour les chantiers archivés

- Un chantier archivé reste consultable par les comptes disposant de `sites.view` (ROADMAP, lot 4).
- Sa modification est refusée côté serveur par le service métier et par la policy.
- L'archivage ne supprime ni la ligne ni la relation avec le client.
- Aucune suppression physique n'est exposée pour les clients ou les chantiers.
- La liste affiche actuellement les chantiers actifs et archivés et propose un filtre d'archivage. Ce comportement par défaut n'est pas arbitré : il correspond au point 5 ci-dessous et aucun filtre par défaut n'a été ajouté.

## Complément pour la revue visuelle

- Un jeu de données fictif est disponible via
  `php artisan planning:demo:clients-sites --confirm`, sur une base locale
  explicitement autorisée uniquement. Il est séparé de `DatabaseSeeder`.
- Il crée 16 clients et 40 chantiers identifiés par le suffixe `(démo)`, dont
  6 chantiers archivés et 2 clients sans chantier.
- Il ne crée aucun compte, aucun mot de passe et aucun événement d'audit.
- Les libellés `en préparation`, `en cours`, `suspendu`, `réception` et
  `terminé` sont uniquement des valeurs de démonstration. Ils ne constituent pas
  la liste officielle des statuts.
- Procédure détaillée et checklist : `docs/PC1-28-DEMO-REVIEW.md`.

## Points à arbitrer avant clôture PC1-28

1. La liste officielle des statuts chantier et les transitions autorisées.
2. Le téléphone et l'e-mail doivent-ils être obligatoires ? Faut-il valider le format du téléphone selon un pays ?
3. Le format d'adresse doit-il être libre ou structuré (voie, code postal, ville, pays) ?
4. La raison sociale ou l'e-mail doivent-ils être uniques ? Aucun index d'unicité n'est imposé sans décision.
5. L'affichage par défaut de la liste des chantiers doit-il inclure les chantiers archivés ou seulement les chantiers actifs ? Le caractère consultable d'un chantier archivé pour les rôles disposant de `sites.view` est acquis et n'est pas remis en question.
6. La gestion de l'archivage des clients et les règles de suppression logique/physique des clients.
7. Faut-il des dates, une référence de commande, un contact chantier ou d'autres champs ? Ils ne sont pas inclus sans validation.

Aucun déploiement n'est effectué par ce lot.
