<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les champs client arbitrés sans découper les adresses historiques.
 *
 * `address` reste la valeur libre des clients existants et devient nullable :
 * aucune heuristique ne tente de la scinder. Les nouveaux clients utilisent les
 * colonnes structurées, obligatoires au niveau du service métier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('contact_name', 190)->nullable()->after('company_name');
            $table->string('street', 255)->nullable()->after('address');
            $table->string('address_complement', 255)->nullable()->after('street');
            $table->string('postal_code', 20)->nullable()->after('address_complement');
            $table->string('city', 120)->nullable()->after('postal_code');
            $table->string('country', 100)->nullable()->after('city');
            $table->timestamp('archived_at')->nullable()->index()->after('country');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->string('address', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les clients créés avec une adresse structurée n'ont pas de champ
        // libre : une chaîne vide évite de rendre le retour arrière impossible
        // sans inventer de données.
        DB::table('clients')->whereNull('address')->update(['address' => '']);

        Schema::table('clients', function (Blueprint $table): void {
            $table->string('address', 500)->nullable(false)->change();
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'contact_name',
                'street',
                'address_complement',
                'postal_code',
                'city',
                'country',
                'archived_at',
            ]);
        });
    }
};
