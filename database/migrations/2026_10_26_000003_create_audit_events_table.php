<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence')->unique();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();
            $table->string('action', 64)->index();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64);
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            // Défense en profondeur : même une requête SQL directe ne doit pas
            // pouvoir réécrire ou supprimer un événement. La détection
            // d'altération par chaîne de hachage complète ce verrou.
            DB::unprepared(
                'CREATE TRIGGER audit_events_prevent_update BEFORE UPDATE ON audit_events
                 FOR EACH ROW SIGNAL SQLSTATE \'45000\'
                 SET MESSAGE_TEXT = \'audit_events est append-only : modification interdite\''
            );

            DB::unprepared(
                'CREATE TRIGGER audit_events_prevent_delete BEFORE DELETE ON audit_events
                 FOR EACH ROW SIGNAL SQLSTATE \'45000\'
                 SET MESSAGE_TEXT = \'audit_events est append-only : suppression interdite\''
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_prevent_delete');
        }

        Schema::dropIfExists('audit_events');
    }
};
