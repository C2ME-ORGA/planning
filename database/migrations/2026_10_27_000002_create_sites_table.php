<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('address', 500);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status', 40);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->index(['client_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
