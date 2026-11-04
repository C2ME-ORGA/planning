# Jeu de démonstration — Lot 3 (PC1-53)

## Objectif

Fournir des données fictives pour la revue visuelle des salariés, périodes d'emploi, équipes et affectations. Ce jeu est distinct de `DatabaseSeeder` et n'est pas exécuté automatiquement.

## Exécution

Depuis une instance locale explicitement autorisée :

```bash
php artisan planning:demo:employees-teams
php artisan planning:demo:employees-teams --confirm
```

La première commande affiche la cible et n'effectue aucune écriture. Avant la seconde, vérifier l'environnement, le pilote, l'hôte et le nom de base affichés. Le mécanisme `DemoDatasetEnvironment` refuse les environnements et bases non autorisés.

## Contenu

- 5 fiches salariés fictives identifiées par `(démo PC1-53)`.
- 6 périodes d'emploi, dont une réembauche et un salarié sorti.
- 2 équipes fictives.
- 4 affectations principales ou temporaires.
- Aucun compte utilisateur, rôle, permission ou événement d'audit n'est créé.

## Limite volontaire

Aucune responsabilité principale d'équipe n'est créée automatiquement : la règle métier exige un salarié couvert par une période d'emploi, un compte applicatif actif lié à ce salarié et un rôle éligible. Le seeder ne doit pas rattacher une vraie identité applicative à un salarié de démonstration ni créer de compte implicite. Tester les responsabilités avec les tests métier dédiés et un compte de test explicitement préparé dans l'environnement de test.

## Idempotence et données existantes

Les enregistrements sont retrouvés par leurs clés fonctionnelles stables (noms marqués et dates). La relance ne doit pas créer de doublons et ne met pas à jour les données existantes. Le seeder s'exécute dans une transaction.

## Nettoyage

Ne pas lancer de suppression générale. Pour nettoyer un environnement de recette, identifier d'abord les salariés portant le marqueur `(démo PC1-53)`, puis contrôler les dépendances et l'historique. Les périodes d'emploi sont append-only et ne peuvent pas être supprimées via le modèle. Le nettoyage automatisé est volontairement absent tant qu'une procédure respectant les contraintes d'historique n'a pas été définie et testée.

## Vérifications

Exécuter les tests dédiés sur la base de test autorisée, puis Pint et Larastan selon les commandes documentées du dépôt. Consigner les commandes et résultats réellement observés dans la PR. Aucun déploiement n'est couvert par ce protocole.
