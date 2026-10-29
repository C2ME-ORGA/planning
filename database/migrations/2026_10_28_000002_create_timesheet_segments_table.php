<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->restrictOnDelete();
            $table->string('category', 32);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->smallInteger('start_offset_minutes');
            $table->smallInteger('end_offset_minutes');
            $table->timestamps();

            $table->index(['timesheet_id', 'start_at']);
            $table->index(['start_at', 'end_at']);
        });

        DB::statement(
            'ALTER TABLE timesheet_segments ADD CONSTRAINT timesheet_segments_category_check '
            ."CHECK (category IN ('travail_effectif', 'trajet', 'pause', 'formation', 'autre'))",
        );

        DB::statement(
            'ALTER TABLE timesheet_segments ADD CONSTRAINT timesheet_segments_period_check '
            .'CHECK (end_at > start_at)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_segments');
    }
};
