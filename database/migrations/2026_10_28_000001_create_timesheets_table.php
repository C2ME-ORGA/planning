<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('brouillon');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->unique(['employee_id', 'period_start'], 'timesheets_employee_period_unique');
            $table->index('status');
        });

        DB::statement(
            'ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check '
            ."CHECK (status IN ('brouillon', 'a_controler', 'a_corriger', 'prete_pour_validation', 'validee'))",
        );

        // Un relevé couvre une semaine civile : lundi (WEEKDAY = 0) et dimanche.
        DB::statement(
            'ALTER TABLE timesheets ADD CONSTRAINT timesheets_week_check '
            .'CHECK (WEEKDAY(period_start) = 0 AND DATEDIFF(period_end, period_start) = 6)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
