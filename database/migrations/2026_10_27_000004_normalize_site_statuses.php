<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise les libellés de statut historiques du jeu de démonstration.
 *
 * Les valeurs inconnues sont laissées intactes : elles ne sont ni devinées ni
 * écrasées. Le service métier refuse toute transition tant qu'un statut
 * inconnu subsiste, ce qui rend le problème visible sans perte de données.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const LEGACY_STATUSES = [
        'en préparation' => 'a_preparer',
        'en cours' => 'en_cours',
        'réception' => 'en_reception',
        'terminé' => 'termine',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_STATUSES as $legacy => $canonical) {
            DB::table('sites')->where('status', $legacy)->update(['status' => $canonical]);
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY_STATUSES as $legacy => $canonical) {
            DB::table('sites')->where('status', $canonical)->update(['status' => $legacy]);
        }
    }
};
