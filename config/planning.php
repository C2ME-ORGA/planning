<?php

$previousAuditKeys = json_decode((string) env('PLANNING_AUDIT_PREVIOUS_KEYS', '{}'), true);

return [

    /*
    |--------------------------------------------------------------------------
    | Comptes
    |--------------------------------------------------------------------------
    |
    | Durée de validité d'un lien d'activation. Valeur par défaut à confirmer
    | lors de la validation humaine du lot 2 (elle n'est pas fixée par la
    | spécification).
    |
    */

    'accounts' => [
        'activation_ttl_minutes' => (int) env('PLANNING_ACTIVATION_TTL_MINUTES', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exceptions de permission
    |--------------------------------------------------------------------------
    |
    | Une exception est révocable et ne peut jamais dépasser douze mois, afin
    | qu'une revue annuelle soit systématiquement possible.
    |
    */

    'exceptions' => [
        'max_duration_months' => (int) env('PLANNING_EXCEPTION_MAX_MONTHS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Salariés et équipes
    |--------------------------------------------------------------------------
    |
    | Délai d'attente du verrou MySQL ciblé qui sérialise les écritures sur les
    | périodes d'emploi, les affectations et la sortie d'un salarié.
    |
    */

    'employees' => [
        'lock_timeout_seconds' => (int) env('PLANNING_EMPLOYEE_LOCK_TIMEOUT_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intégrité du journal d'audit
    |--------------------------------------------------------------------------
    |
    | La clé d'audit doit être distincte d'APP_KEY et comporter au moins
    | 32 caractères. `previous_keys` est un objet JSON {identifiant: clé}
    | utilisé uniquement pour vérifier les événements signés avant une
    | rotation de clé. Exemple :
    | PLANNING_AUDIT_PREVIOUS_KEYS='{"audit-v0":"..."}'
    |
    */

    'audit' => [
        'key_id' => (string) env('PLANNING_AUDIT_KEY_ID', 'audit-v1'),
        'key' => (string) env('PLANNING_AUDIT_KEY', ''),
        'previous_keys' => is_array($previousAuditKeys) ? $previousAuditKeys : [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jeu de données de démonstration
    |--------------------------------------------------------------------------
    |
    | La commande `planning:demo:clients-sites` refuse de s'exécuter sur une
    | base qui n'est pas explicitement autorisée. La liste par défaut ne
    | contient que des bases locales de développement, de test ou de
    | démonstration. Ne jamais y ajouter une base de production.
    |
    | `allowed_sqlite_paths` liste les fichiers SQLite réellement autorisés.
    | Chaque entrée doit être un chemin absolu vers un fichier précis : aucun
    | motif (`*.sqlite`), aucun répertoire et aucun chemin relatif ne sont
    | acceptés. Le chemin cible est canonicalisé puis comparé à cette liste ;
    | les liens symboliques, les bases `:memory:` et les URI `file:` sont
    | refusés. La valeur par défaut ne contient que le fichier SQLite standard
    | de l'application, créé par l'administrateur local.
    |
    */

    'demo' => [
        'allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],
        'allowed_databases' => array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) env('PLANNING_DEMO_ALLOWED_DATABASES', 'planning_c2me,planning_c2me_test,planning_c2me_demo')),
        ))),
        'allowed_sqlite_paths' => array_values(array_filter(array_map(
            static fn (string $path): string => trim($path),
            explode(',', (string) env('PLANNING_DEMO_ALLOWED_SQLITE_PATHS', database_path('database.sqlite'))),
        ), static fn (string $path): bool => $path !== '')),
    ],

];
