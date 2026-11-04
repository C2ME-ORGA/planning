<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('half_day', 10);
            $table->timestamps();

            $table->unique(
                ['site_id', 'team_id', 'work_date', 'half_day'],
                'planning_assignment_unique_slot',
            );
            $table->index(['work_date', 'half_day']);
            $table->index(['team_id', 'work_date', 'half_day']);
            $table->index(['site_id', 'work_date']);
        });

        DB::statement(
            'ALTER TABLE planning_assignments ADD CONSTRAINT planning_assignments_half_day_check '
            ."CHECK (half_day IN ('morning', 'afternoon'))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_assignments');
    }
};
