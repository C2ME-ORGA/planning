<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('global_generation')->default(1);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::table('authentication_state')->insert([
            'id' => 1,
            'global_generation' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Supprimer cette table réinitialiserait la génération globale et
        // pourrait rendre valides des sessions d'une génération antérieure.
        // Un retour arrière doit être manuel, après purge des sessions et
        // rotation des jetons persistants, avec le code PC1-12 hors service.
        throw new RuntimeException(
            'La table authentication_state ne peut pas être supprimée automatiquement : '
            .'un rollback réinitialiserait la génération globale. '
            .'Utilisez la procédure de retour arrière documentée.',
        );
    }
};
