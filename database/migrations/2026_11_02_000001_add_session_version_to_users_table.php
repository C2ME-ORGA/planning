<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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
        throw new RuntimeException(
            'Rollback interdit : users.session_version participe à la révocation des sessions. '
            .'Son retrait nécessite une procédure manuelle après désactivation du code PC1-12 '
            .'et invalidation de toutes les sessions et de tous les jetons remember-me.',
        );
    }
};
