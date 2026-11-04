<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_overlap_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('first_segment_id')
                ->constrained('timesheet_segments')
                ->cascadeOnDelete();
            $table->foreignId('second_segment_id')
                ->constrained('timesheet_segments')
                ->cascadeOnDelete();
            // Séquence du dernier événement de frontière de cycle
            // (`timesheet.reopened` ou `timesheet.correction_resumed`), 0 pour
            // le cycle initial. Une nouvelle frontière invalide la revue.
            $table->unsignedBigInteger('cycle_sequence')->default(0);
            // Paire canonique `min(segment_id):max(segment_id)` : l’ordre de
            // détection ne crée jamais deux revues pour la même paire.
            $table->string('pair_key', 64);
            $table->string('fingerprint', 64);
            $table->string('decision', 16);
            $table->string('reason', 500);
            $table->foreignId('reviewed_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['timesheet_id', 'pair_key'], 'timesheet_overlap_reviews_pair_unique');
            $table->index(['timesheet_id', 'cycle_sequence'], 'timesheet_overlap_reviews_cycle_index');
        });

        DB::statement(
            'ALTER TABLE timesheet_overlap_reviews ADD CONSTRAINT timesheet_overlap_reviews_decision_check '
            ."CHECK (decision IN ('accepted', 'rejected'))",
        );

        DB::statement(
            'ALTER TABLE timesheet_overlap_reviews ADD CONSTRAINT timesheet_overlap_reviews_order_check '
            .'CHECK (first_segment_id < second_segment_id)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_overlap_reviews');
    }
};
