<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_locks', function (Blueprint $table): void {
            // Clé primaire : deux insertions concurrentes du même nom ne
            // peuvent pas réussir. Utilisé pour les opérations uniques
            // (bootstrap Bureau/Direction).
            $table->string('name', 100)->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_locks');
    }
};
