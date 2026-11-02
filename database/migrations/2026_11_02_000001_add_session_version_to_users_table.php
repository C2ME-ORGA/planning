<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Compteur monotone : incrémenté à chaque révocation. Une session
            // créée avant la révocation porte une version différente et est
            // rejetée, quel que soit le pilote de session.
            $table->unsignedBigInteger('session_version')->default(0)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_version');
        });
    }
};
