<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            // Nullable : les événements antérieurs à la clé d'audit dédiée ont
            // été signés avec APP_KEY et restent vérifiables via ce champ nul.
            $table->string('key_id', 32)->nullable()->after('hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['key_id']);
            $table->dropColumn('key_id');
        });
    }
};
