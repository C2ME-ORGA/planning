<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'kind', 'start_date']);
            $table->index(['team_id', 'start_date']);
            $table->index(['employee_id', 'end_date']);
        });

        DB::statement(
            'ALTER TABLE team_assignments ADD CONSTRAINT team_assignments_kind_check '
            ."CHECK (kind IN ('primary', 'temporary'))",
        );

        DB::statement(
            'ALTER TABLE team_assignments ADD CONSTRAINT team_assignments_dates_check '
            .'CHECK (end_date IS NULL OR end_date >= start_date)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('team_assignments');
    }
};
