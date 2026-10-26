<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);

            // Lien facultatif vers le compte applicatif. Un compte ne peut être
            // rattaché qu'à un seul salarié. La suppression physique du compte
            // ne doit jamais supprimer la fiche RH ni son historique.
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
