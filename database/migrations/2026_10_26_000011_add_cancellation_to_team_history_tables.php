<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_assignments', function (Blueprint $table): void {
            // Une affectation future peut être annulée explicitement avant son
            // début. L'enregistrement reste conservé pour l'historique.
            $table->timestamp('cancelled_at')->nullable()->after('end_date');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->index(['employee_id', 'cancelled_at']);
        });

        Schema::table('team_leaderships', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('end_date');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->index(['team_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('team_assignments', function (Blueprint $table): void {
            $table->dropIndex(['employee_id', 'cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        Schema::table('team_leaderships', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
