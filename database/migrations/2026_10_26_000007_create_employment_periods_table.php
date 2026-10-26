<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
            $table->index(['employee_id', 'end_date']);
        });

        // Contrainte simple possible ; l'absence de chevauchement est garantie
        // par le service transactionnel (MySQL n'offre pas d'exclusion de
        // plages équivalente à PostgreSQL).
        DB::statement(
            'ALTER TABLE employment_periods ADD CONSTRAINT employment_periods_dates_check '
            .'CHECK (end_date IS NULL OR end_date >= start_date)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_periods');
    }
};
