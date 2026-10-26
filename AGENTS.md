# AGENTS.md — Consignes pour les agents de développement

## Contexte
Application Planning C2ME, dépôt indépendant pour SARL C2ME, dédiée au planning et aux relevés d’heures. Lire d’abord `docs/SPECIFICATIONS.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TESTING.md` et `ROADMAP.md`.

## Règles impératives
- Examiner l’état réel du dépôt et le diff avant de modifier.
- Ne pas recréer l’application Laravel ni remplacer des dépendances majeures sans preuve d’un besoin et validation.
- Ne pas coder plusieurs lots en une seule tâche. Travailler sur un lot/incrément explicitement annoncé.
- Ne pas inventer de règles RH, de paie, de conservation ou de sécurité en l’absence de décision. Signaler les inconnues.
- Toutes les permissions doivent être vérifiées côté serveur, pas uniquement dans l’interface.
- Toutes les règles de calcul doivent être centralisées et couvertes par des tests chiffrés.
- Les changements de workflow doivent passer par des actions métier et être auditables.
- Ne jamais recalculer silencieusement des périodes validées ou clôturées.
- Le mode hors ligne ne doit jamais écraser une version en conflit ou déclarer synchronisé avant confirmation serveur.
- Ne jamais inscrire secrets, données réelles de salariés, mots de passe, jetons ou contenu RH inutile dans Git ou les logs.
- Ne jamais déployer en production sans autorisation explicite.
- Pas de calcul de paie, majorations, virement ou retenue automatique dans le MVP.

## Qualité attendue
- Ajouter ou modifier les tests avec le code.
- Exécuter les commandes de tests, formatage et analyse statique déjà prévues dans le dépôt.
- Ne pas affirmer qu’un test passe s’il n’a pas été exécuté.
- Garder les changements minimaux, lisibles et cohérents avec les conventions du dépôt.
- Mettre à jour les documents si le comportement ou l’architecture change.
- Ne pas intégrer de secrets ou fichiers locaux `.env`.

## Fin de chaque lot
Présenter le résumé, les fichiers modifiés, les commandes exécutées et leurs résultats, les écarts à la spécification et les risques restants. S’arrêter au point de validation humaine indiqué dans `ROADMAP.md`.
