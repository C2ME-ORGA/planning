<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('direction', 32)->nullable();
            $table->unsignedInteger('volume_minutes');
            $table->unsignedInteger('balance_before_minutes');
            $table->unsignedInteger('balance_after_minutes');
            $table->char('month', 7);
            $table->char('quarter', 7)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('reason', 500)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('source_reference', 255)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['employee_id', 'occurred_at', 'id'],
                'counter_movements_employee_occurred_index',
            );
            $table->index(
                ['employee_id', 'month'],
                'counter_movements_employee_month_index',
            );
        });

        DB::statement(
            'ALTER TABLE counter_movements ADD CONSTRAINT counter_movements_type_check '
            ."CHECK (type IN ('credit', 'usage', 'payment', 'adjustment'))",
        );

        DB::statement(
            'ALTER TABLE counter_movements ADD CONSTRAINT counter_movements_volume_check '
            .'CHECK (volume_minutes > 0)',
        );

        DB::statement(
            'ALTER TABLE counter_movements ADD CONSTRAINT counter_movements_balance_check '
            .'CHECK (balance_before_minutes >= 0 AND balance_after_minutes >= 0)',
        );

        DB::statement(
            'ALTER TABLE counter_movements ADD CONSTRAINT counter_movements_direction_check '
            ."CHECK ((type = 'adjustment' AND direction IN ('credit', 'debit')) "
            ."OR (type <> 'adjustment' AND direction IS NULL))",
        );

        DB::statement(
            'ALTER TABLE counter_movements ADD CONSTRAINT counter_movements_reason_check '
            ."CHECK (type <> 'adjustment' OR reason IS NOT NULL)",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_movements');
    }
};
