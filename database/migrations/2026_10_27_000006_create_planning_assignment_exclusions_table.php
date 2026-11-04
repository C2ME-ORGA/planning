<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_assignment_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planning_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['planning_assignment_id', 'employee_id'],
                'planning_assignment_exclusion_unique',
            );
            $table->index('employee_id', 'planning_assignment_exclusion_employee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_assignment_exclusions');
    }
};
