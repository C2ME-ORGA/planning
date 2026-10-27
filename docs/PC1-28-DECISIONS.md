# PC1-28 — Décisions et points métier à confirmer

## Périmètre implémenté

- Client : raison sociale, adresse, téléphone et e-mail.
- Chantier : nom, adresse, client associé et statut.
- L'archivage utilise une date d'archivage et conserve la ligne, le client associé et l'historique d'audit.
- Les modifications de chantier archivé sont bloquées par le service métier.
- Les permissions sont vérifiées côté serveur et les opérations de création, modification et archivage sont journalisées.

## Hypothèses techniques explicites

- La raison sociale, l'adresse client, le nom du chantier, l'adresse chantier, le client associé et le statut sont requis par les formulaires et services.
- Téléphone et e-mail client sont facultatifs.
- Le statut est conservé comme texte court. Aucune liste de statuts, transition ou signification n'est inventée à ce stade.
- Les chantiers ne sont pas supprimables physiquement. La suppression d'un client est également interdite par la policy ; la règle de suppression/archivage des clients n'est pas encore définie fonctionnellement.

## Points à arbitrer avant clôture PC1-28

1. La liste officielle des statuts chantier et les transitions autorisées.
2. Le téléphone et l'e-mail doivent-ils être obligatoires ? Faut-il valider le format du téléphone selon un pays ?
3. Le format d'adresse doit-il être libre ou structuré (voie, code postal, ville, pays) ?
4. La raison sociale ou l'e-mail doivent-ils être uniques ? Aucun index d'unicité n'est imposé sans décision.
5. Un chantier archivé reste-t-il consultable pour tous les rôles autorisés et doit-il apparaître par défaut dans les listes ?
6. La gestion de l'archivage des clients et les règles de suppression logique/physique des clients.
7. Faut-il des dates, une référence de commande, un contact chantier ou d'autres champs ? Ils ne sont pas inclus sans validation.

Aucun déploiement n'est effectué par ce lot.
