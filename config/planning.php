<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrateurs temporaires du panneau Filament
    |--------------------------------------------------------------------------
    |
    | Cette liste permet de n'ouvrir le panneau d'administration qu'à des
    | adresses explicitement autorisées, en attendant le modèle de rôles
    | prévu au lot 2. Laisser la variable vide interdit l'accès à tous.
    |
    */

    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('PLANNING_ADMIN_EMAILS', '')),
    ))),

];
