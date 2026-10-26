<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_leaderships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'start_date']);
            $table->index(['employee_id', 'start_date']);
            $table->index(['team_id', 'end_date']);
        });

        DB::statement(
            'ALTER TABLE team_leaderships ADD CONSTRAINT team_leaderships_dates_check '
            .'CHECK (end_date IS NULL OR end_date >= start_date)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('team_leaderships');
    }
};
